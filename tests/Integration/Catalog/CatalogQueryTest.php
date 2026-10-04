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

    public function testSimilarProductsPreferCategoriesThenFillWithBrandWithoutDuplicatesOrDrafts(): void
    {
        $brand = new Brand('Related Brand', 'related-brand');
        $brand->publish();
        $category = new Category('Related Category', 'related-category');
        $category->publish();
        $secondCategory = new Category('Second Related Category', 'second-related-category');
        $secondCategory->publish();
        $this->entityManager->persist($secondCategory);
        $current = $this->product('RELATED-CURRENT', 'Current', 'related-current', true, $brand, $category);
        $current->addCategory($secondCategory);
        $categoryOnly = $this->product('RELATED-CATEGORY', 'Category', 'related-category-product', true, null, $category, 12_345, 4);
        $both = $this->product('RELATED-BOTH', 'Both', 'related-both', true, $brand, $category);
        $both->addCategory($secondCategory);
        $categoryOnly->addImage('storefront/images/hero-automotive.svg', 'Related image');
        $this->product('RELATED-DRAFT', 'Draft', 'related-draft', false, $brand, $category);
        $this->product('RELATED-OTHER', 'Unrelated', 'related-other', true);
        for ($i = 0; $i < 10; ++$i) {
            $this->product('RELATED-BRAND-'.$i, 'Brand '.$i, 'related-brand-'.$i, true, $brand);
        }
        $this->entityManager->flush();

        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();
        $items = $this->catalog->similarProducts($current->id());
        $queries = $debugData->getData();
        self::assertSame(1, array_sum(array_map(count(...), $queries)), 'Related cards must resolve in one read query.');
        self::assertCount(8, $items);
        $ids = array_column($items, 'id');
        self::assertCount(8, array_unique($ids));
        self::assertNotContains($current->id(), $ids);
        self::assertSame([$both->id(), $categoryOnly->id()], array_slice($ids, 0, 2));
        self::assertSame(12_345, $items[1]->sellPrice?->minorAmount());
        self::assertSame(4, $items[1]->quantity);
        self::assertSame('Related image', $items[1]->imageAlt);
        self::assertNotContains('RELATED-DRAFT', array_column($items, 'sku'));
        self::assertNotContains('RELATED-OTHER', array_column($items, 'sku'));

        $unrelated = $this->catalog->product('related-other');
        self::assertSame([], $this->catalog->similarProducts($unrelated->id));
        self::assertSame([], $this->catalog->similarProducts(0));
        $category->unpublish();
        $secondCategory->unpublish();
        $brand->unpublish();
        $this->entityManager->flush();
        self::assertSame([], $this->catalog->similarProducts($current->id()));
    }

    public function testStockPriorityPrecedesEverySortAndPaginationIsDeterministic(): void
    {
        $brand = new Brand('Stock Brand', 'stock-brand');
        $brand->publish();
        $category = new Category('Stock Category', 'stock-category');
        $category->publish();
        $this->product('STOCK-ZERO', 'A', 'stock-zero', true, $brand, $category, 100, 0);
        $blocked = $this->product('STOCK-BLOCKED', 'B', 'stock-blocked', true, $brand, $category, 200, 8);
        $this->product('STOCK-ONE', 'C', 'stock-one', true, $brand, $category, 300, 2);
        $this->product('STOCK-TWO', 'C', 'stock-two', true, $brand, $category, 300, 2);
        $this->entityManager->flush();
        $this->connection->update('commerce_product_inventory', ['available_for_sale' => 0], ['product_id' => $blocked->id()]);

        foreach (CatalogSort::cases() as $sort) {
            $criteria = new CatalogCriteria(query: 'STOCK-', categorySlug: $category->slug(), brandSlug: $brand->slug(), sort: $sort);
            $all = $this->catalog->search($criteria)->items;
            self::assertSame([true, true, false, false], array_column($all, 'sellable'));
            $paged = [];
            for ($page = 1; $page <= 4; ++$page) {
                $paged[] = $this->catalog->search(new CatalogCriteria(query: 'STOCK-', sort: $sort, page: $page, perPage: 1))->items[0]->id;
            }
            self::assertSame(array_column($all, 'id'), $paged);
            if (CatalogSort::PriceAscending === $sort || CatalogSort::NameAscending === $sort) {
                self::assertSame(['STOCK-ONE', 'STOCK-TWO', 'STOCK-ZERO', 'STOCK-BLOCKED'], array_column($all, 'sku'));
            }
        }
    }

    public function testSimilarProductsPutSellableBrandMatchesBeforeUnavailableCategoryMatches(): void
    {
        $brand = new Brand('Stock Related', 'stock-related');
        $brand->publish();
        $category = new Category('Stock Related', 'stock-related');
        $category->publish();
        $current = $this->product('STOCK-CURRENT', 'Current', 'stock-current', true, $brand, $category);
        $this->product('STOCK-CATEGORY', 'Category', 'stock-category-related', true, null, $category, 100, 0);
        $this->product('STOCK-BRAND', 'Brand', 'stock-brand-related', true, $brand);
        $this->entityManager->flush();
        self::assertSame(['STOCK-BRAND', 'STOCK-CATEGORY'], array_column($this->catalog->similarProducts($current->id()), 'sku'));
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
