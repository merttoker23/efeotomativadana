<?php

declare(strict_types=1);

namespace App\Tests\Controller\Storefront\Catalog;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A catalogue listing reads as one long grid rather than as a sequence of pages.
 *
 * Thirty products arrive with the page and the next thirty of the very same query are asked for as
 * the customer reaches the bottom of what is already on screen. This test pins the contract that
 * makes that work rather than the scripting that performs it: the address the page offers is the
 * next page of the same query with the same filters, the pages do not overlap, and the last page
 * offers nothing further — which is what tells the scroll to stop. The whole catalogue, a category
 * and a brand all read the same way, so all three are asked for it here.
 *
 * It also pins what a customer changes without noticing: the search terms, the brand, the stock
 * filter and the sort all travel into the next address, and changing the sort starts the listing
 * again from the first thirty products rather than from wherever it had reached.
 */
final class CategoryInfiniteScrollTest extends WebTestCase
{
    private const string CATEGORY = 'fren-sistemleri-infilme';

    private const string BRAND = 'infilme-markasi';

    private const int PRODUCTS = 65;

    private const int PER_PAGE = 30;

    private KernelBrowser $client;

    private Connection $connection;

    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $manager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;

        $this->catalogue();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheCategoryRendersThirtyProductsAndOffersTheNextThirty(): void
    {
        $crawler = $this->category();

        self::assertSame(self::PER_PAGE, $this->products($crawler)->count());
        self::assertSame('catalog-infinite-scroll', $this->listing($crawler)->attr('data-controller'));
        self::assertSame('/kategori/'.self::CATEGORY.'?sort=newest&page=2', $this->nextUrl($crawler));
        self::assertSame(1, $crawler->filter('[data-catalog-infinite-scroll-target="sentinel"]')->count());
    }

    /** Each page is the next thirty of the same products: no overlap, and no gap either. */
    public function testThreePagesCoverTheCategoryExactlyOnceAndTheLastPageOffersNothing(): void
    {
        $firstPage = $this->category();
        $secondPage = $this->category(page: 2);
        $lastPage = $this->category(page: 3);

        $first = $this->slugs($firstPage);
        $second = $this->slugs($secondPage);
        $last = $this->slugs($lastPage);

        self::assertCount(self::PER_PAGE, $first);
        self::assertCount(self::PER_PAGE, $second);
        self::assertCount(self::PRODUCTS - (2 * self::PER_PAGE), $last);
        self::assertSame([], array_intersect($first, $second), 'The second page repeated a product from the first.');
        self::assertSame([], array_intersect($second, $last), 'The last page repeated a product from the second.');
        self::assertCount(self::PRODUCTS, array_unique([...$first, ...$second, ...$last]));

        self::assertSame('/kategori/'.self::CATEGORY.'?sort=newest&page=3', $this->nextUrl($secondPage));
        // Nothing after the last page, and nothing left to watch: that is what stops the scroll.
        self::assertSame('', $this->nextUrl($lastPage));
        self::assertSame(0, $lastPage->filter('[data-catalog-infinite-scroll-target="sentinel"]')->count());
    }

    /**
     * Every card carries the key the scroll matches on, so a product can arrive on screen once.
     */
    public function testEveryProductOnThePageCarriesItsOwnKey(): void
    {
        $keys = $this->slugs($this->category());

        self::assertCount(self::PER_PAGE, $keys);
        self::assertNotContains('', $keys);
        self::assertCount(self::PER_PAGE, array_unique($keys));
    }

    /**
     * What the customer chose travels into the next page.
     *
     * The address is built from the request that produced the page, so a listing that is scrolled
     * cannot come back showing the unfiltered, unsorted catalogue under the filter that was picked.
     */
    public function testTheSearchBrandStockFilterAndSortTravelIntoTheNextPage(): void
    {
        $crawler = $this->category(query: [
            'q' => 'Par',
            'brand' => self::BRAND,
            'availability' => 'in-stock',
            'sort' => 'price-asc',
        ]);

        $next = $this->nextUrl($crawler);

        self::assertSame(self::PER_PAGE, $this->products($crawler)->count());
        self::assertStringContainsString('page=2', $next);
        self::assertStringContainsString('q=Par', $next);
        self::assertStringContainsString('brand='.self::BRAND, $next);
        self::assertStringContainsString('availability=in-stock', $next);
        self::assertStringContainsString('sort=price-asc', $next);
        // The category is the address the listing is on, so it is not repeated as a filter.
        self::assertStringNotContainsString('category=', $next);
    }

    /**
     * Changing the sort is a request, and a request carries no page number, so the listing begins
     * again from the first thirty products.
     */
    public function testChangingTheSortStartsTheListingAgainFromTheFirstThirty(): void
    {
        $names = $this->category(query: ['sort' => 'name-asc'])
            ->filter('.product-grid > .product-card .product-name')
            ->each(static fn (Crawler $name): string => trim($name->text()));

        self::assertCount(self::PER_PAGE, $names);
        $expected = $names;
        sort($expected, \SORT_NATURAL | \SORT_FLAG_CASE);
        self::assertSame($expected, $names, 'The first page of a name-sorted listing is not the first thirty names.');
    }

    /**
     * With scripts running the listing is the grid; the pagination is what a browser that cannot run
     * them gets instead, so it is rendered inside `noscript` and nowhere else.
     */
    public function testTheClassicPaginationIsRenderedOnlyForABrowserWithoutScripts(): void
    {
        $this->category();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertMatchesRegularExpression('#<noscript>\s*<nav class="pagination"#', $html);
        self::assertSame(1, preg_match_all('#<nav class="pagination"#', $html));
        self::assertStringContainsString('page=2', $html);
    }

    /**
     * The same reading for the whole catalogue and for a brand.
     *
     * A customer browses in a brand exactly as they browse in a category, so the other two
     * listings scroll the way this one does: thirty on the page, a watchable bottom, and the
     * address of the next thirty of the very same query rather than a link to a page of results.
     */
    public function testTheCatalogueIndexAndTheBrandListingScrollTheWayTheCategoryDoes(): void
    {
        $brandPath = '/marka/'.self::BRAND;

        foreach ([
            '/katalog' => '/katalog?sort=newest&page=2',
            $brandPath => $brandPath.'?sort=newest&page=2',
        ] as $path => $expected) {
            $crawler = $this->request($path);
            $html = (string) $this->client->getResponse()->getContent();

            self::assertSame('catalog-infinite-scroll', $this->listing($crawler)->attr('data-controller'), $path.' is not read as one long grid.');
            self::assertSame($expected, $this->nextUrl($crawler), $path.' does not offer the next page of itself.');
            self::assertSame(1, $crawler->filter('[data-catalog-infinite-scroll-target="sentinel"]')->count(), $path.' leaves nothing to watch.');
            self::assertSame(self::PER_PAGE, $this->products($crawler)->count(), $path.' did not render thirty products.');
            // And it is the scroll that replaces the pagination here, exactly as it is in a category.
            self::assertSame(1, preg_match_all('#<nav class="pagination"#', $html), $path.' shows more than the one noscript pagination.');
        }
    }

    /** @param array<string, string> $query */
    private function category(int $page = 1, array $query = []): Crawler
    {
        $parameters = $query;
        if ($page > 1) {
            $parameters['page'] = (string) $page;
        }

        return $this->request('/kategori/'.self::CATEGORY, $parameters);
    }

    /** @param array<string, string> $query */
    private function request(string $path, array $query = []): Crawler
    {
        $this->client->request('GET', $path, $query);
        self::assertResponseIsSuccessful();

        return $this->client->getCrawler();
    }

    private function listing(Crawler $crawler): Crawler
    {
        $listing = $crawler->filter('.catalog-listing');
        self::assertSame(1, $listing->count(), 'The listing is not wrapped in the scroll controller.');

        return $listing;
    }

    private function nextUrl(Crawler $crawler): string
    {
        return (string) $this->listing($crawler)->attr('data-catalog-infinite-scroll-next-url-value');
    }

    /** @return list<string> */
    private function slugs(Crawler $crawler): array
    {
        return $this->products($crawler)->each(
            static fn (Crawler $card): string => (string) $card->attr('data-product'),
        );
    }

    private function products(Crawler $crawler): Crawler
    {
        return $crawler->filter('.product-grid > .product-card');
    }

    private function catalogue(): void
    {
        $category = new Category('Fren Sistemi (infilme)', self::CATEGORY);
        $category->publish();
        $brand = new Brand('Marka (infilme)', self::BRAND);
        $brand->publish();
        $this->manager->persist($category);
        $this->manager->persist($brand);

        for ($index = 1; $index <= self::PRODUCTS; ++$index) {
            $product = new Product(
                sprintf('INF-%03d', $index),
                sprintf('Parça %03d', $index),
                sprintf('infilme-parca-%03d', $index),
                brand: $brand,
            );
            $product->addCategory($category);
            $product->publish();
            $this->manager->persist($product);
            $this->manager->persist(new ProductPrice(
                $product,
                Money::ofMinor(1_000 * $index, 'TRY'),
                TaxCategory::of('replacement-part'),
                TaxRate::fromBasisPoints(2_000),
            ));
            $this->manager->persist(new ProductInventory($product, 5));
        }

        $this->manager->flush();
    }
}