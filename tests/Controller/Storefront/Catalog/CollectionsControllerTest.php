<?php

declare(strict_types=1);

namespace App\Tests\Controller\Storefront\Catalog;

use App\Entity\Catalog\Brand;
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

/**
 * The collections page: the published products that are on a discount at this moment.
 *
 * A sale in this store is a second price with a window around it, not a flag somebody sets, so the
 * question this page answers is not a stored state — it is the same three conditions the pricing
 * module evaluates for every price it prints. That is what these tests pin: the page lists a
 * product whose sale has started and has not ended, and lists nothing else. A sale that has ended
 * or has not begun is a price the store is not discounting today, and a draft product is not on
 * sale to anybody.
 *
 * The page is the catalogue's own listing asked a different question, so the second half of this
 * class is what keeps it that way: the sidebar, the sort and the scrolling are the catalogue's.
 */
final class CollectionsControllerTest extends WebTestCase
{
    private const COLLECTIONS = '/yeni/koleksiyonlar';

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $manager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->entityManager = $manager;
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testThePageListsOnlyThePublishedProductsOnADiscountRightNow(): void
    {
        $this->discounted('COL-OK', 'Aktif İndirimli Ürün', 'aktif-indirimli-urun', '2020-01-01', '2999-01-01');

        // Open on both sides: a sale with no window at all is the case a range condition gets wrong.
        $this->discounted('COL-OPEN', 'Pencere Ayarlanmamış Ürün', 'pencere-ayarlanmamis-urun');

        $this->discounted('COL-FUTURE', 'Henüz Başlamayan Ürün', 'henuz-baslamayan-urun', '2999-01-01', '2999-06-01');
        $this->discounted('COL-ENDED', 'Süresi Geçmiş Ürün', 'suresi-gecmis-urun', '2020-01-01', '2021-01-01');

        // Priced, in stock, and not discounted: the ordinary product this page is not for.
        $this->product('COL-FULL', 'İndirimsiz Ürün', 'indirimsiz-urun');

        // Discounted, and not published: a draft is on sale to nobody.
        $this->discounted('COL-DRAFT', 'Taslak İndirimli Ürün', 'taslak-indirimli-urun', '2020-01-01', '2999-01-01', published: false);
        $this->entityManager->flush();

        $this->client->request('GET', self::COLLECTIONS);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Koleksiyonlar');
        self::assertSelectorTextContains('.product-grid', 'Aktif İndirimli Ürün');
        self::assertSelectorTextContains('.product-grid', 'Pencere Ayarlanmamış Ürün');

        foreach (['Henüz Başlamayan Ürün', 'Süresi Geçmiş Ürün', 'İndirimsiz Ürün', 'Taslak İndirimli Ürün'] as $excluded) {
            self::assertSelectorTextNotContains('.product-grid', $excluded);
        }

        // The count the listing reports is a count of the same set, so a page that filtered in the
        // repository but not in its own summary would be caught here.
        self::assertSelectorTextContains('.catalog-summary', '2 üründen 1 - 2');
    }

    /**
     * The same page with nothing on sale is not an error and not a broken search, and it says so.
     */
    public function testAPageWithNoActiveDiscountSaysSoRatherThanReportingAnEmptySearch(): void
    {
        $this->product('COL-NONE', 'İndirimsiz Ürün', 'indirimsiz-urun');
        $this->entityManager->flush();

        $this->client->request('GET', self::COLLECTIONS);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.catalog-empty', 'Şu anda indirimli ürün yok');
        self::assertSelectorCount(0, '.product-card');
    }

    /**
     * Collections is the catalogue's listing asked a different question, not a listing system of its
     * own: the sidebar, the sort and the infinite scroll are the ones every other listing uses, and
     * the address of the next page stays on this route rather than drifting back to the catalogue.
     */
    public function testTheListingFurnitureIsTheCataloguesOwn(): void
    {
        $brand = new Brand('Bosch', 'bosch');
        $brand->publish();
        $this->entityManager->persist($brand);
        $this->discounted('COL-BRAND', 'Markalı İndirimli Ürün', 'markali-indirimli-urun', '2020-01-01', '2999-01-01', $brand);
        for ($i = 1; $i <= 30; ++$i) {
            $this->discounted(
                sprintf('COL-PAGE-%02d', $i),
                sprintf('Sayfalı İndirimli Ürün %02d', $i),
                sprintf('sayfali-indirimli-urun-%02d', $i),
                '2020-01-01',
                '2999-01-01',
            );
        }
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::COLLECTIONS);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.catalog-listing[data-controller~="catalog-infinite-scroll"]');
        self::assertSelectorExists('.sort-form select[name="sort"]');
        self::assertSelectorExists('.catalog-filters form.catalog-filter-form');
        // Thirty discounted products, so the listing really does have a further page to ask for.
        self::assertSame(30, $crawler->filter('.product-card')->count());

        $next = $crawler->filter('.catalog-listing')->attr('data-catalog-infinite-scroll-next-url-value');
        self::assertIsString($next);
        self::assertStringStartsWith(self::COLLECTIONS.'?', $next);
        self::assertStringContainsString('page=2', $next);

        // The filter form posts back to this page, so choosing a brand stays inside the collection.
        self::assertSame(self::COLLECTIONS, $crawler->filter('form.catalog-filter-form')->attr('action'));

        $this->client->request('GET', self::COLLECTIONS.'?brand=bosch');

        self::assertSelectorTextContains('.product-grid', 'Markalı İndirimli Ürün');
        self::assertSelectorTextContains('.catalog-summary', '1 üründen 1 - 1');
    }

    /**
     * The link the navigation used to print as dimmed text is a real link now, and the band knows
     * which page it is on — in the desktop row and in the mobile panel, which is the one a phone
     * actually shows.
     */
    public function testTheNavigationLinksToCollectionsAndMarksItAsTheCurrentPage(): void
    {
        $crawler = $this->client->request('GET', self::COLLECTIONS);

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(0, '.main-navigation [aria-disabled="true"]');

        $desktop = $crawler->filter('.main-navigation a[href="'.self::COLLECTIONS.'"]');
        self::assertCount(1, $desktop, 'The navigation does not link to the collections page.');
        self::assertSame('Koleksiyonlar', trim($desktop->text()));
        self::assertSame(
            1,
            $crawler->filter('.main-navigation a[aria-current="page"]')->count(),
            'The desktop band did not mark collections as the current page.',
        );
        self::assertSame(
            1,
            $crawler->filter('#mobile-navigation a[href="'.self::COLLECTIONS.'"][aria-current="page"]')->count(),
            'The mobile panel did not mark collections as the current page.',
        );

        // Collections does not light up the catalogue link, and the catalogue does not light up the
        // collections link: they are two different pages and only one of them is the current one.
        $this->client->request('GET', '/yeni/katalog');

        self::assertSelectorCount(0, '.main-navigation a[href="'.self::COLLECTIONS.'"][aria-current="page"]');
        self::assertSelectorCount(0, '#mobile-navigation a[href="'.self::COLLECTIONS.'"][aria-current="page"]');
        self::assertSame(1, $this->client->getCrawler()->filter('.main-navigation a[href="/yeni/katalog"][aria-current="page"]')->count());
    }

    private function discounted(
        string $sku,
        string $name,
        string $slug,
        ?string $startsAt = null,
        ?string $endsAt = null,
        ?Brand $brand = null,
        bool $published = true,
    ): Product {
        $product = $this->product($sku, $name, $slug, $brand, $published);
        $price = new ProductPrice(
            $product,
            Money::ofMinor(15_000, 'TRY'),
            TaxCategory::of('replacement-part'),
            TaxRate::fromBasisPoints(2_000),
        );
        $price->scheduleSale(
            Money::ofMinor(10_000, 'TRY'),
            null === $startsAt ? null : new \DateTimeImmutable($startsAt, new \DateTimeZone('UTC')),
            null === $endsAt ? null : new \DateTimeImmutable($endsAt, new \DateTimeZone('UTC')),
        );
        $this->entityManager->persist($price);

        return $product;
    }

    private function product(
        string $sku,
        string $name,
        string $slug,
        ?Brand $brand = null,
        bool $published = true,
    ): Product {
        $product = new Product($sku, $name, $slug, brand: $brand);
        if ($published) {
            $product->publish();
        }
        $this->entityManager->persist($product);
        $this->entityManager->persist(new ProductInventory($product, 5));

        return $product;
    }
}
