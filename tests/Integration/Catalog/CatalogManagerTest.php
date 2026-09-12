<?php

namespace App\Tests\Integration\Catalog;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Module\Catalog\CatalogManager;
use App\Module\Catalog\Exception\CatalogConflict;
use App\Module\Catalog\PublicationStatus;
use App\Repository\Catalog\BrandRepository;
use App\Repository\Catalog\CategoryRepository;
use App\Repository\Catalog\ProductRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class CatalogManagerTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItCreatesALocallyManagedCatalogWithGeneratedSlugs(): void
    {
        $brand = $this->manager()->createBrand('Bosch');
        $category = $this->manager()->createCategory('Brake Systems');
        $product = $this->manager()->createProduct(' bal-001 ', 'Brake Pad', brand: $brand);
        $product->addCategory($category);
        $this->manager()->saveProduct($product);

        self::assertSame('bosch', $brand->slug());
        self::assertSame('brake-systems', $category->slug());
        self::assertSame('BAL-001', $product->sku());
        self::assertSame('brake-pad', $product->slug());
        self::assertSame([$category], $product->categories());
    }

    public function testItRejectsDuplicateSkuBeforeDatabaseFlush(): void
    {
        $this->manager()->createProduct('BAL-001', 'Front Brake Pad', 'front-brake-pad');

        $this->expectException(CatalogConflict::class);

        $this->manager()->createProduct(' bal-001 ', 'Rear Brake Pad', 'rear-brake-pad');
    }

    public function testItRejectsDuplicateProductSlugBeforeDatabaseFlush(): void
    {
        $this->manager()->createProduct('BAL-001', 'Front Brake Pad', 'brake-pad');

        $this->expectException(CatalogConflict::class);

        $this->manager()->createProduct('BAL-002', 'Rear Brake Pad', 'brake-pad');
    }

    public function testRenamingAProductKeepsItsPublicSlugStable(): void
    {
        $product = $this->manager()->createProduct('BAL-001', 'Front Brake Pad', 'front-brake-pad');

        $this->manager()->updateProduct($product, 'Ceramic Front Brake Pad', 'Low-dust compound');

        self::assertSame('Ceramic Front Brake Pad', $product->name());
        self::assertSame('Low-dust compound', $product->description());
        self::assertSame('front-brake-pad', $product->slug());
    }

    public function testPublishedLookupExcludesDraftProductsByDefault(): void
    {
        $product = $this->manager()->createProduct('BAL-001', 'Front Brake Pad', 'front-brake-pad');

        self::assertNull($this->products()->findOnePublishedBySlug('front-brake-pad'));

        $this->manager()->publishProduct($product);
        self::assertSame($product, $this->products()->findOnePublishedBySlug('front-brake-pad'));

        $this->manager()->unpublishProduct($product);
        self::assertNull($this->products()->findOnePublishedBySlug('front-brake-pad'));
    }

    public function testItUpdatesAndPublishesCategoriesAndBrandsWithoutChangingSlugs(): void
    {
        $brand = $this->manager()->createBrand('Bosch');
        $category = $this->manager()->createCategory('Brake Systems');

        $this->manager()->updateBrand($brand, 'Bosch Automotive');
        $this->manager()->publishBrand($brand);
        $this->manager()->updateCategory($category, 'Vehicle Brake Systems');
        $this->manager()->publishCategory($category);

        self::assertSame('Bosch Automotive', $brand->name());
        self::assertSame('bosch', $brand->slug());
        self::assertSame(PublicationStatus::Published, $brand->publicationStatus());
        self::assertSame('Vehicle Brake Systems', $category->name());
        self::assertSame('brake-systems', $category->slug());
        self::assertSame(PublicationStatus::Published, $category->publicationStatus());
    }

    private function manager(): CatalogManager
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $products = $entityManager->getRepository(Product::class);
        $categories = $entityManager->getRepository(Category::class);
        $brands = $entityManager->getRepository(Brand::class);
        self::assertInstanceOf(ProductRepository::class, $products);
        self::assertInstanceOf(CategoryRepository::class, $categories);
        self::assertInstanceOf(BrandRepository::class, $brands);

        return new CatalogManager($products, $categories, $brands, $entityManager, new AsciiSlugger());
    }

    private function products(): ProductRepository
    {
        $repository = self::getContainer()->get('doctrine')->getRepository(Product::class);
        self::assertInstanceOf(ProductRepository::class, $repository);

        return $repository;
    }
}
