<?php

namespace App\Tests\Integration\Catalog;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Module\Catalog\CatalogSource;
use App\Module\Catalog\ProductIdentifierType;
use App\Repository\Catalog\ProductRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogPersistenceTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testDatabaseRejectsDuplicateProductSku(): void
    {
        $this->entityManager->persist(new Product('BAL-001', 'Front Brake Pad', 'front-brake-pad'));
        $this->entityManager->persist(new Product('BAL-001', 'Rear Brake Pad', 'rear-brake-pad'));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->entityManager->flush();
    }

    public function testDatabaseRejectsDuplicateProductSlug(): void
    {
        $this->entityManager->persist(new Product('BAL-001', 'Front Brake Pad', 'brake-pad'));
        $this->entityManager->persist(new Product('BAL-002', 'Rear Brake Pad', 'brake-pad'));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->entityManager->flush();
    }

    public function testDatabaseRejectsDuplicateCategorySlug(): void
    {
        $this->entityManager->persist(new Category('Brake Systems', 'brake-systems'));
        $this->entityManager->persist(new Category('Braking', 'brake-systems'));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->entityManager->flush();
    }

    public function testDatabaseRejectsDuplicateBrandSlug(): void
    {
        $this->entityManager->persist(new Brand('Bosch', 'bosch'));
        $this->entityManager->persist(new Brand('Bosch Automotive', 'bosch'));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->entityManager->flush();
    }

    public function testCategoryHierarchyAndProductMembershipPersist(): void
    {
        $brand = new Brand('Bosch', 'bosch', CatalogSource::External);
        $root = new Category('Brake Systems', 'brake-systems');
        $child = new Category('Brake Pads', 'brake-pads');
        $child->changeParent($root);
        $product = new Product('BAL-001', 'Front Brake Pad', 'front-brake-pad', CatalogSource::External, $brand);
        $product->addCategory($child);

        $this->entityManager->persist($brand);
        $this->entityManager->persist($root);
        $this->entityManager->persist($child);
        $this->entityManager->persist($product);
        $this->entityManager->flush();
        $productId = $product->id();
        $childId = $child->id();
        $this->entityManager->clear();

        $reloadedProduct = $this->entityManager->find(Product::class, $productId);
        $reloadedChild = $this->entityManager->find(Category::class, $childId);
        self::assertInstanceOf(Product::class, $reloadedProduct);
        self::assertInstanceOf(Category::class, $reloadedChild);
        self::assertSame('brake-pads', $reloadedProduct->categories()[0]->slug());
        self::assertSame('brake-systems', $reloadedChild->parent()?->slug());
        self::assertSame('BAL-001', $reloadedChild->products()[0]->sku());
        self::assertSame(CatalogSource::External, $reloadedProduct->source());
        $reloadedBrand = $reloadedProduct->brand();
        self::assertInstanceOf(Brand::class, $reloadedBrand);
        self::assertSame(CatalogSource::External, $reloadedBrand->source());
        self::assertSame('BAL-001', $reloadedBrand->products()[0]->sku());
    }

    public function testProductsCanBeFoundByTypedAutomotiveIdentifier(): void
    {
        $frontProduct = new Product('BAL-001', 'Front Brake Pad', 'front-brake-pad');
        $frontProduct->addIdentifier(ProductIdentifierType::Oem, '04E 115 561 H');
        $frontProduct->addIdentifier(ProductIdentifierType::Manufacturer, 'BR-100');
        $rearProduct = new Product('BAL-002', 'Rear Brake Pad', 'rear-brake-pad');
        $rearProduct->addIdentifier(ProductIdentifierType::Oem, '04E 115 561 H');
        $this->entityManager->persist($frontProduct);
        $this->entityManager->persist($rearProduct);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $repository = $this->entityManager->getRepository(Product::class);
        self::assertInstanceOf(ProductRepository::class, $repository);
        $found = $repository->findByIdentifier(ProductIdentifierType::Oem, ' 04e 115 561 h ');

        self::assertSame(
            ['BAL-001', 'BAL-002'],
            array_map(static fn (Product $product): string => $product->sku(), $found),
        );
    }

    public function testImagesAndAttributesPersistInDefinedOrder(): void
    {
        $product = new Product('BAL-001', 'Front Brake Pad', 'front-brake-pad');
        $product->addImage('/images/rear.jpg', 'Rear view', 20);
        $product->addImage('/images/front.jpg', 'Front view', 10);
        $product->setAttribute('disc-diameter', '290 mm');
        $this->entityManager->persist($product);
        $this->entityManager->flush();
        $productId = $product->id();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Product::class, $productId);
        self::assertInstanceOf(Product::class, $reloaded);
        self::assertSame(
            ['/images/front.jpg', '/images/rear.jpg'],
            array_map(static fn ($image): string => $image->path(), $reloaded->images()),
        );
        self::assertSame('disc-diameter', $reloaded->attributes()[0]->key());
        self::assertSame('290 mm', $reloaded->attributes()[0]->value());
    }
}
