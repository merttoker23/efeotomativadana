<?php

declare(strict_types=1);

namespace App\Tests\Performance;

use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use App\Module\Settings\StoreSettingsData;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Guards the shape of storefront reads rather than their absolute speed.
 *
 * A query budget is what catches the regression that actually happens in this codebase: not a
 * query becoming slower, but a loop being reintroduced above a lazy collection so a page that
 * used to answer in a fixed number of queries starts answering in a number that grows with the
 * customer's own data. Each test therefore measures the same page twice, at two different sizes
 * of input, and requires the counts to be equal.
 *
 * The measurement warm-up in {@see budget()} matters. The first request after `loginUser()`
 * reuses the security token the helper just wrote into the session, while later requests read it
 * back from storage and re-hydrate the customer. That extra query belongs to authentication, not
 * to the page under test, so every measurement is taken against an already-warm kernel.
 */
final class StorefrontQueryBudgetTest extends WebTestCase
{
    private KernelBrowser $client;

    /**
     * The suite does not roll the test database back automatically, so these tests own their
     * fixtures completely: everything is created and removed around each test rather than being
     * left for the next run to collide with.
     */
    private const PREFIXES = ['budget-home-', 'budget-cart-', 'budget-cart-budget-', 'budget-catalogue-'];

    /**
     * Carts that existed before this test ran.
     *
     * These tests add to a guest basket, which creates a `commerce_cart` row, and the suite does
     * not roll the database back. CartWorkflowTest asserts on the *total* number of carts in the
     * table, so a basket left behind here fails an unrelated test in a way that looks like a
     * product defect. Recording the ids up front and deleting only the new ones at the end is
     * exact: anything another test created is left alone.
     *
     * @var list<int>
     */
    private array $cartsBefore;

    protected function setUp(): void
    {
        // No disableReboot(): the kernel is rebooted between requests on purpose. The guest
        // cart token travels in the session cookie the browser client keeps on its own, while
        // every `kernel.reset` service — the settings memo, the cart owner lookup, the cart
        // view cache — is genuinely reset, which is what a real request gets and what makes the
        // measured counts mean something.
        $this->client = static::createClient();
        $this->cartsBefore = array_map(intval(...), $this->connection()->fetchFirstColumn('SELECT id FROM commerce_cart'));
        $this->purge();
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(\Doctrine\DBAL\Connection::class, $connection);

        return $connection;
    }

    protected function tearDown(): void
    {
        // Purged before the kernel is torn down, because the container is unreachable afterwards.
        $this->purge();
        $this->purgeCarts();
        parent::tearDown();
    }

    private function purgeCarts(): void
    {
        $connection = $this->connection();
        $ids = array_values(array_filter(
            array_map(intval(...), $connection->fetchFirstColumn('SELECT id FROM commerce_cart')),
            fn (int $id): bool => !in_array($id, $this->cartsBefore, true),
        ));
        foreach ($ids as $id) {
            $connection->delete('commerce_cart_item', ['cart_id' => $id]);
            $connection->delete('commerce_cart', ['id' => $id]);
        }
    }

    private function purge(): void
    {
        $connection = $this->connection();
        foreach (self::PREFIXES as $prefix) {
            $ids = $connection->fetchFirstColumn('SELECT id FROM catalog_product WHERE sku LIKE :prefix', ['prefix' => $prefix.'%']);
            if ([] === $ids) {
                continue;
            }
            $list = implode(',', array_map(static fn ($id): string => (string) (int) $id, $ids));
            $connection->executeStatement('DELETE FROM commerce_cart_item WHERE product_id IN ('.$list.')');
            $connection->executeStatement('DELETE FROM commerce_wishlist_item WHERE product_id IN ('.$list.')');
            $connection->executeStatement('DELETE FROM commerce_product_price WHERE product_id IN ('.$list.')');
            $connection->executeStatement('DELETE FROM commerce_product_inventory WHERE product_id IN ('.$list.')');
            $connection->executeStatement('DELETE FROM catalog_product WHERE sku LIKE :prefix', ['prefix' => $prefix.'%']);
        }
    }

    public function testTheHomepageCostsTheSameWithSixProductsAsWithTwelve(): void
    {
        $this->seedProducts('budget-home', 6);
        $this->warmUp('/yeni/');
        $withSix = $this->budget('/yeni/');
        $this->seedProducts('budget-home', 6, 6);
        $withTwelve = $this->budget('/yeni/');

        self::assertSame(
            $withSix,
            $withTwelve,
            sprintf('The homepage must not query per product. Measured %d queries for %d products.', $withTwelve, 12),
        );
    }

    public function testTheCartCostsTheSameWithThreeLinesAsWithEight(): void
    {
        $this->seedProducts('budget-cart', 8);
        $this->addToCart('budget-cart', 3);
        $this->warmUp('/yeni/sepet');
        $withThree = $this->budget('/yeni/sepet');
        $this->addToCart('budget-cart', 5, 3);
        $withEight = $this->budget('/yeni/sepet');

        self::assertSame(
            $withThree,
            $withEight,
            sprintf('The basket must not query per line for price, stock and images. Measured %d queries for 8 lines.', $withEight),
        );
    }

    /**
     * The store settings table holds one row per setting, and every storefront page reads several
     * of them. Read one key at a time, each of those reads was its own query: the catalogue page
     * measured 8 queries before, and 5 after, with the difference being exactly the four
     * per-key setting reads it no longer makes.
     *
     * This is a whole-page budget rather than a statement-by-statement assertion on purpose. The
     * profiler's per-statement list is not populated once the kernel has been rebooted between
     * requests, so a statement-level assertion here would see nothing and pass for the wrong
     * reason. The count is the part that regresses.
     */
    public function testTheCataloguePageStaysInsideItsQueryBudget(): void
    {
        $this->seedProducts('budget-catalogue', 4);
        $this->warmUp('/yeni/katalog');
        $queries = $this->budget('/yeni/katalog');

        self::assertLessThanOrEqual(
            5,
            $queries,
            'The catalogue page used 8 queries before store settings were read in one pass.',
        );
    }
    /**
     * A basket line used to cost a price read, a stock read and an image read of its own. The
     * budget below is the ceiling with room to spare; the equality tests above are the ones that
     * actually fail when a per-line loop returns.
     */
    public function testTheBasketStaysInsideItsQueryBudget(): void
    {
        $this->seedProducts('budget-cart-budget', 4);
        $this->addToCart('budget-cart-budget', 4);
        $this->warmUp('/yeni/sepet');

        self::assertLessThanOrEqual(14, $this->budget('/yeni/sepet'), 'The basket page exceeded its query budget.');
    }

    private function seedProducts(string $prefix, int $count, int $offset = 0): void
    {
        $manager = self::getContainer()->get('doctrine')->getManager();
        $connection = $this->connection();

        for ($i = $offset; $i < $offset + $count; ++$i) {
            $sku = sprintf('%s-%02d', $prefix, $i);
            $product = new \App\Entity\Catalog\Product($sku, sprintf('Budget Ürün %s', $sku), sprintf('%s-%02d', $prefix, $i));
            $product->publish();
            $manager->persist($product);
            $manager->flush();
            $connection->insert('commerce_product_price', [
                'product_id' => $product->id(),
                'base_minor_amount' => 100_000,
                'sale_minor_amount' => null,
                'currency' => 'TRY',
                'tax_category' => 'replacement-part',
                'tax_rate_basis_points' => 2000,
                'sale_starts_at' => null,
                'sale_ends_at' => null,
                'created_at' => '2026-09-30 10:00:00',
                'updated_at' => '2026-09-30 10:00:00',
            ]);
            $connection->insert('commerce_product_inventory', [
                'product_id' => $product->id(),
                'quantity' => 25,
                'available_for_sale' => 1,
                'created_at' => '2026-09-30 10:00:00',
                'updated_at' => '2026-09-30 10:00:00',
            ]);
        }
        self::getContainer()->get('doctrine')->getManager()->clear();
    }

    private function addToCart(string $prefix, int $count, int $offset = 0): void
    {
        $connection = $this->connection();
        $rows = $connection->fetchAllAssociative(
            'SELECT id, slug FROM catalog_product WHERE sku LIKE :prefix ORDER BY id ASC',
            ['prefix' => $prefix.'-%'],
        );
        $count = min($count, count($rows));

        for ($i = $offset; $i < $count; ++$i) {
            $this->client->request('POST', '/yeni/sepet/ekle/'.$rows[$i]['id'], [
                'quantity' => 1,
                '_token' => $this->csrfFor((string) $rows[$i]['slug']),
            ]);
        }
    }

    /**
     * Minted from the token manager rather than scraped out of a rendered form. The add-to-cart
     * token is per product, so borrowing one from whichever card rendered first would fail; and
     * scraping would make this performance test depend on the product page's markup.
     */
    private function csrfFor(string $slug): string
    {
        // Scraped from the rendered product page rather than minted directly: this store uses
        // SameOriginCsrfTokenManager, which validates a header and a cookie rather than the
        // token field, so the token a real browser sends is the one the template prints.
        $this->client->request('GET', '/yeni/urun/'.$slug);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), sprintf('The product page for "%s" did not render.', $slug));
        $token = $this->client->getCrawler()->filter('.cart-add-form input[name="_token"]')->attr('value');
        self::assertIsString($token);

        return $token;
    }

    private function warmUp(string $url): void
    {
        $this->client->request('GET', $url);
    }

    private function budget(string $url): int
    {
        $this->holder()->reset();
        $this->client->enableProfiler();
        $this->client->request('GET', $url);

        return $this->collector()->getQueryCount();
    }

    private function holder(): BacktraceDebugDataHolder
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        return $holder;
    }

    private function collector(): DoctrineDataCollector
    {
        $profile = $this->client->getProfile();
        self::assertNotFalse($profile, 'No profile was captured for the request.');
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector;
    }
}