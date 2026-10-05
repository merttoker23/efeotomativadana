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
    public function testFacetsApplyOtherFiltersAndExcludeTheirOwnSelection(): void
    {
        $firstBrand = new Brand('Facet A', 'facet-a');
        $secondBrand = new Brand('Facet B', 'facet-b');
        $firstCategory = new Category('Facet X', 'facet-x');
        $secondCategory = new Category('Facet Y', 'facet-y');
        foreach ([$firstBrand, $secondBrand, $firstCategory, $secondCategory] as $option) {
            $option->publish();
        }
        $sale = $this->product('FACET-AX', 'Facet lamp', 'facet-ax', true, $firstBrand, $firstCategory, 30_000);
        $this->product('FACET-BX', 'Facet lamp', 'facet-bx', true, $secondBrand, $firstCategory, 20_000, 0);
        $this->product('FACET-AY', 'Facet filter', 'facet-ay', true, $firstBrand, $secondCategory, 40_000);
        $this->product('FACET-DRAFT', 'Facet lamp', 'facet-draft', false, $secondBrand, $secondCategory);
        $this->entityManager->flush();
        $this->connection->update('commerce_product_price', ['sale_minor_amount' => 10_000], ['product_id' => $sale->id()]);

        $criteria = new CatalogCriteria(categorySlug: 'facet-x', brandSlug: 'facet-a');
        self::assertSame(['facet-a' => 1, 'facet-b' => 1], $this->facetCounts($this->catalog->brandFacets($criteria, 24)));
        self::assertSame(['facet-x' => 1, 'facet-y' => 1], $this->facetCounts($this->catalog->categoryFacets($criteria, 24)));
        foreach ([
            [new CatalogCriteria(query: 'lamp', brandSlug: 'facet-a'), ['facet-a' => 1, 'facet-b' => 1], ['facet-x' => 1]],
            [new CatalogCriteria(categorySlug: 'facet-x', inStockOnly: true), ['facet-a' => 1], ['facet-x' => 1, 'facet-y' => 1]],
            [new CatalogCriteria(categorySlug: 'facet-x', maxPriceMinor: 10_000), ['facet-a' => 1], ['facet-x' => 1]],
            [new CatalogCriteria(categorySlug: 'facet-x', onSaleOnly: true), ['facet-a' => 1], ['facet-x' => 1]],
        ] as [$filtered, $brandCounts, $categoryCounts]) {
            self::assertSame($brandCounts, $this->facetCounts($this->catalog->brandFacets($filtered, 24)));
            self::assertSame($categoryCounts, $this->facetCounts($this->catalog->categoryFacets($filtered, 24)));
        }
        $selected = new CatalogCriteria(query: 'filter', categorySlug: 'facet-x', brandSlug: 'facet-b');
        self::assertSame(['facet-b' => 0], $this->facetCounts($this->catalog->brandFacets($selected, 1)));
        self::assertSame(['facet-x' => 0], $this->facetCounts($this->catalog->categoryFacets($selected, 1)));
        self::assertSame(['facet-a' => 2, 'facet-b' => 1], $this->facetCounts($this->catalog->brandFacets(new CatalogCriteria(brandSlug: 'facet-b'), 1)));
        self::assertSame(['facet-x' => 2, 'facet-y' => 1], $this->facetCounts($this->catalog->categoryFacets(new CatalogCriteria(categorySlug: 'facet-y'), 1)));
    }

    /** @param list<\App\Module\Catalog\Query\CatalogOption> $options @return array<string, int> */
    private function facetCounts(array $options): array
    {
        $counts = array_column($options, 'productCount', 'slug');
        ksort($counts);

        return $counts;
    }

    public function testSearchTokensMatchInAnyOrderAcrossNamesAndIdentifiers(): void
    {
        $lamp = $this->product('TOKEN-LAMP', 'LAMBA SİS ACCENT 98-99 RH (BEYAZ)', 'token-lamp', true);
        $this->product('TOKEN-MISSING', 'LAMBA SİS CIVIC', 'token-missing', true);
        $mixed = $this->product('TOKEN-MIXED', 'LAMBA SİS', 'token-mixed', true);
        $mixed->addIdentifier(ProductIdentifierType::Reference, 'ACCENT-REF');
        $lamp->addIdentifier(ProductIdentifierType::Oem, 'OEM%_!42');
        $this->entityManager->flush();
        foreach (['lamba accent', 'accent lamba', " lamba\t accent "] as $query) {
            self::assertSame(['TOKEN-LAMP', 'TOKEN-MIXED'], array_column($this->catalog->search(new CatalogCriteria(query: $query))->items, 'sku'));
        }
        self::assertSame(['TOKEN-LAMP'], array_column($this->catalog->search(new CatalogCriteria(query: 'OEM%_!42'))->items, 'sku'));
        self::assertSame([], $this->catalog->search(new CatalogCriteria(query: 'lamba nonexistent'))->items);
    }

    public function testRelevanceKeepsStockFirstThenExactCodesNamesAndTokenMatches(): void
    {
        $this->product('ACCENT', 'Unrelated', 'rank-sku', true);
        $oem = $this->product('RANK-OEM', 'Unrelated', 'rank-oem', true);
        $oem->addIdentifier(ProductIdentifierType::Oem, 'ACCENT');
        $this->product('RANK-EXACT', 'Accent', 'rank-exact', true);
        $this->product('RANK-PREFIX', 'Accent lamp', 'rank-prefix', true);
        $this->product('RANK-NAME', 'Lamp Accent', 'rank-name', true);
        $identifier = $this->product('RANK-ID', 'Unrelated', 'rank-id', true);
        $identifier->addIdentifier(ProductIdentifierType::Manufacturer, 'X-ACCENT-123');
        $this->product('RANK-UNAVAILABLE', 'Accent', 'rank-unavailable', true, quantity: 0);
        $this->entityManager->flush();
        self::assertSame(['ACCENT', 'RANK-OEM', 'RANK-EXACT', 'RANK-PREFIX', 'RANK-NAME', 'RANK-ID', 'RANK-UNAVAILABLE'], array_column($this->catalog->search(new CatalogCriteria(query: 'accent', sort: CatalogSort::NameAscending))->items, 'sku'));
    }

    public function testPriceRangeUsesEffectiveSaleAndContextBoundsIgnoreSelectedRange(): void
    {
        $brand = new Brand('Range Brand', 'range-brand');
        $brand->publish();
        $category = new Category('Range Category', 'range-category');
        $category->publish();
        $sale = $this->product('RANGE-SALE', 'Range lamp', 'range-sale', true, $brand, $category, 300_000);
        $expired = $this->product('RANGE-EXPIRED', 'Range lamp', 'range-expired', true, $brand, $category, 220_000);
        $future = $this->product('RANGE-FUTURE', 'Range lamp', 'range-future', true, $brand, $category, 230_000);
        $this->product('RANGE-LOW', 'Range lamp', 'range-low', true, $brand, $category, 49_999);
        $missing = $this->product('RANGE-MISSING', 'Range lamp', 'range-missing', true, $brand, $category);
        $this->product('RANGE-OTHER', 'Range lamp', 'range-other', true, price: 999_999);
        $this->entityManager->flush();
        $this->connection->update('commerce_product_price', ['sale_minor_amount' => 50_000], ['product_id' => $sale->id()]);
        $this->connection->update('commerce_product_price', ['sale_minor_amount' => 10_000, 'sale_ends_at' => '2000-01-01 00:00:00'], ['product_id' => $expired->id()]);
        $this->connection->update('commerce_product_price', ['sale_minor_amount' => 10_000, 'sale_starts_at' => '2099-01-01 00:00:00'], ['product_id' => $future->id()]);
        $this->connection->delete('commerce_product_price', ['product_id' => $missing->id()]);
        $criteria = new CatalogCriteria(query: 'range', categorySlug: 'range-category', brandSlug: 'range-brand', inStockOnly: true, sort: CatalogSort::PriceAscending, minPriceMinor: 50_000, maxPriceMinor: 220_000);
        self::assertSame(['RANGE-SALE', 'RANGE-EXPIRED'], array_column($this->catalog->search($criteria)->items, 'sku'));
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();
        self::assertSame(['min' => 49_999, 'max' => 230_000], $this->catalog->priceBounds($criteria));
        self::assertSame(1, array_sum(array_map(count(...), $debugData->getData())));
        self::assertSame(['RANGE-SALE'], array_column($this->catalog->search(new CatalogCriteria(query: 'range', onSaleOnly: true, maxPriceMinor: 50_000))->items, 'sku'));
        self::assertSame([], $this->catalog->search(new CatalogCriteria(query: 'range', maxPriceMinor: 0))->items);
        self::assertSame(['RANGE-SALE'], array_column($this->catalog->search(new CatalogCriteria(query: 'range-sale', minPriceMinor: 50_000))->items, 'sku'));
        self::assertSame([], $this->catalog->search(new CatalogCriteria(query: 'range-sale', minPriceMinor: 50_001))->items);
        self::assertSame(['min' => null, 'max' => null], $this->catalog->priceBounds(new CatalogCriteria(query: 'nonexistent')));
    }

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
