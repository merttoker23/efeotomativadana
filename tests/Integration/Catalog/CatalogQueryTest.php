<?php

namespace App\Tests\Integration\Catalog;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Module\Catalog\ProductIdentifierType;
use App\Module\Catalog\Query\CatalogCriteria;
use App\Module\Catalog\Query\CatalogQuery;
use App\Module\Catalog\Query\CatalogSort;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogQueryTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private CatalogQuery $catalog;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->catalog = self::getContainer()->get(CatalogQuery::class);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testSearchFindsPublishedProductsByEveryAutomotiveCodeButNeverDrafts(): void
    {
        $published = $this->product('FILTER-001', 'Oil Filter', 'oil-filter', published: true);
        $published->addIdentifier(ProductIdentifierType::Oem, '04E 115 561 H');
        $published->addIdentifier(ProductIdentifierType::Manufacturer, 'MANN-W712');
        $published->addIdentifier(ProductIdentifierType::Reference, 'REF-ALTERNATE-44');
        $draft = $this->product('FILTER-SECRET', 'Draft Filter', 'draft-filter', published: false);
        $draft->addIdentifier(ProductIdentifierType::Oem, '04E 115 561 H');
        $this->entityManager->flush();

        foreach (['04e 115 561 h', 'mann-w712', 'ref-alter', 'filter-001', 'Oil Fil'] as $query) {
            $page = $this->catalog->search(new CatalogCriteria(query: $query));
            self::assertSame(['FILTER-001'], array_map(static fn ($item): string => $item->sku, $page->items));
        }

        self::assertSame([], $this->catalog->search(new CatalogCriteria(query: '%'))->items);
    }

    public function testFiltersAndPriceSortingUseLocalPublishedCatalogState(): void
    {
        $brand = new Brand('Bosch', 'bosch');
        $brand->publish();
        $category = new Category('Brake Systems', 'brake-systems');
        $category->publish();
        $first = $this->product('BRAKE-001', 'Front Pad', 'front-pad', true, $brand, $category, 129_900, 5);
        $this->product('BRAKE-002', 'Rear Pad', 'rear-pad', true, $brand, $category, 89_900, 0);
        $this->product('OTHER-001', 'Air Filter', 'air-filter', true, null, null, 49_900, 8);
        $this->entityManager->flush();

        $page = $this->catalog->search(new CatalogCriteria(
            categorySlug: 'brake-systems',
            brandSlug: 'bosch',
            inStockOnly: true,
            sort: CatalogSort::PriceAscending,
        ));

        self::assertSame(1, $page->totalItems);
        self::assertSame('BRAKE-001', $page->items[0]->sku);
        self::assertSame(129_900, $page->items[0]->sellPrice?->minorAmount());
        self::assertSame(5, $page->items[0]->quantity);
        self::assertTrue($page->items[0]->sellable);
        self::assertSame($first->slug(), $page->items[0]->slug);
    }

    private function product(
        string $sku,
        string $name,
        string $slug,
        bool $published,
        ?Brand $brand = null,
        ?Category $category = null,
        int $price = 10_000,
        int $quantity = 1,
    ): Product {
        $product = new Product($sku, $name, $slug, brand: $brand);
        if ($published) {
            $product->publish();
        }
        if (null !== $category) {
            $product->addCategory($category);
        }
        if (null !== $brand) {
            $this->entityManager->persist($brand);
        }
        if (null !== $category) {
            $this->entityManager->persist($category);
        }
        $this->entityManager->persist($product);
        $this->entityManager->persist(new ProductPrice(
            $product,
            Money::ofMinor($price, 'TRY'),
            TaxCategory::of('replacement-part'),
            TaxRate::fromBasisPoints(2_000),
        ));
        $this->entityManager->persist(new ProductInventory($product, $quantity));

        return $product;
    }
}
