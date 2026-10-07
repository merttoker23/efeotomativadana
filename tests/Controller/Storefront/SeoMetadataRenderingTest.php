<?php

declare(strict_types=1);

namespace App\Tests\Controller\Storefront;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Cms\BlogPost;
use App\Entity\Cms\InformationPage;
use App\Entity\Customer\CustomerUser;
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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The metadata a search engine reads is the metadata in the HTML, so these tests assert on
 * rendered output rather than on the objects that produced it. A resolver that computes the
 * right canonical and a template that never renders it still ships a page with no canonical.
 */
final class SeoMetadataRenderingTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $manager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->entityManager = $manager;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testEveryPublicPageHasExactlyOneTitleDescriptionAndCanonical(): void
    {
        $product = $this->publishedProduct('SEO-R-1', 'Yag Filtresi', 'yag-filtresi', 12_500, 4);
        $category = $this->publishedCategory('Frenler', 'frenler');
        $this->product($product, $category);
        $brand = new Brand('Bosch', 'bosch');
        $brand->publish();
        $this->entityManager->persist($brand);
        $post = new BlogPost('Yag Degisimi', 'yag-degisimi', 'Periyodu nedir?', 'Uzun govde metni.');
        $post->setPublished(true);
        $this->entityManager->persist($post);
        $page = new InformationPage('Kargo Politikasi', 'kargo-politikasi', 'Kargo metni.');
        $page->setPublished(true);
        $this->entityManager->persist($page);
        $this->entityManager->flush();

        $pages = [
            '/' => 'https://localhost/',
            '/katalog' => 'https://localhost/katalog',
            '/kategori/frenler' => 'https://localhost/kategori/frenler',
            '/markalar' => 'https://localhost/markalar',
            '/marka/bosch' => 'https://localhost/marka/bosch',
            '/urun/yag-filtresi' => 'https://localhost/urun/yag-filtresi',
            '/blog' => 'https://localhost/blog',
            '/blog/yag-degisimi' => 'https://localhost/blog/yag-degisimi',
            '/bilgi' => 'https://localhost/bilgi',
            '/bilgi/kargo-politikasi' => 'https://localhost/bilgi/kargo-politikasi',
        ];

        foreach ($pages as $uri => $canonical) {
            $crawler = $this->client->request('GET', $uri);
            self::assertResponseIsSuccessful($uri);

            self::assertCount(1, $crawler->filter('head title'), $uri);
            self::assertCount(1, $crawler->filter('head meta[name="description"]'), $uri);
            self::assertCount(1, $crawler->filter('head link[rel="canonical"]'), $uri);
            self::assertSame($canonical, $crawler->filter('head link[rel="canonical"]')->attr('href'), $uri);
            self::assertNotSame('', trim((string) $crawler->filter('head meta[name="description"]')->attr('content')), $uri);
            self::assertNotSame('', trim((string) $crawler->filter('head title')->text()), $uri);
        }
    }

    public function testAPublicPageCarriesNoRobotsDirective(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('head meta[name="robots"]'));
    }

    public function testTheProductPageStatesTheSamePriceCurrencyAndAvailabilityTheCartWillCharge(): void
    {
        $product = $this->publishedProduct('SEO-R-2', 'Yag Filtresi', 'yag-filtresi-2', 129_900, 6);

        $crawler = $this->client->request('GET', '/urun/yag-filtresi-2');

        self::assertResponseIsSuccessful();
        $offer = $this->productGraph($crawler)['offers'];
        self::assertSame('1299.00', $offer['price']);
        self::assertSame('TRY', $offer['priceCurrency']);
        self::assertSame('https://schema.org/InStock', $offer['availability']);
    }

    public function testTheProductStructuredDataPriceFollowsAnActiveSaleRatherThanTheListPrice(): void
    {
        $product = $this->publishedProduct('SEO-R-3', 'Indirimli Filtre', 'indirimli-filtre', 100_000, 3);
        $this->entityManager->getRepository(ProductPrice::class);
        $this->connection->executeStatement(
            'UPDATE commerce_product_price SET sale_minor_amount = 75000, sale_starts_at = ?, sale_ends_at = ? WHERE product_id = ?',
            ['2020-01-01 00:00:00', '2099-01-01 00:00:00', $product->id()],
        );

        $crawler = $this->client->request('GET', '/urun/indirimli-filtre');

        self::assertResponseIsSuccessful();
        self::assertSame('750.00', $this->productGraph($crawler)['offers']['price']);
    }

    public function testAnOutOfStockProductIsAdvertisedAsOutOfStock(): void
    {
        $this->publishedProduct('SEO-R-4', 'Tukenen Filtre', 'tukenen-filtre', 5_000, 0);

        $crawler = $this->client->request('GET', '/urun/tukenen-filtre');

        self::assertResponseIsSuccessful();
        self::assertSame('https://schema.org/OutOfStock', $this->productGraph($crawler)['offers']['availability']);
    }

    public function testAPricelessProductAdvertisesNoOfferAtAllRatherThanAZeroPrice(): void
    {
        $product = $this->publishedProduct('SEO-R-5', 'Fiyatsiz Parca', 'fiyatsiz-parca', null, 2);

        $crawler = $this->client->request('GET', '/urun/fiyatsiz-parca');

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('offers', $this->productGraph($crawler));
        self::assertStringNotContainsString('"price"', (string) $this->client->getResponse()->getContent());
    }

    public function testTheProductGraphNamesTheProductSkuAndItsOwnCanonicalUrl(): void
    {
        $this->publishedProduct('SEO-R-6', 'MANN Filtre', 'mann-filtre', 4_250, 1);

        $crawler = $this->client->request('GET', '/urun/mann-filtre');

        self::assertResponseIsSuccessful();
        $graph = $this->productGraph($crawler);
        self::assertSame('MANN Filtre', $graph['name']);
        self::assertSame('SEO-R-6', $graph['sku']);
        self::assertSame('https://localhost/urun/mann-filtre', $graph['url']);
    }

    public function testOrganizationAndBreadcrumbGraphsArePresentOnAProductPage(): void
    {
        $product = $this->publishedProduct('SEO-R-7', 'Kırıntı Filtresi', 'kirinti-filtresi', 9_900, 2);

        $crawler = $this->client->request('GET', '/urun/kirinti-filtresi');

        self::assertResponseIsSuccessful();
        $types = array_column($this->jsonLd($crawler), '@type');
        self::assertContains('Organization', $types);
        self::assertContains('BreadcrumbList', $types);

        $trail = $this->graphOfType($crawler, 'BreadcrumbList');
        self::assertSame([1, 2, 3], array_column($trail['itemListElement'], 'position'));
        self::assertSame('https://localhost/', $trail['itemListElement'][0]['item']);
    }

    public function testTheHomePageCarriesNoBreadcrumbListBecauseItIsTheStartOfTheTrail(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertNotContains('BreadcrumbList', array_column($this->jsonLd($crawler), '@type'));
    }

    public function testABlogPostIsOfferedAsAnArticleWithItsOwnCanonicalUrl(): void
    {
        $post = new BlogPost('Yag Degisimi', 'yag-degisimi-2', 'Periyodu nedir?', 'Uzun govde metni.');
        $post->setPublished(true);
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/blog/yag-degisimi-2');

        self::assertResponseIsSuccessful();
        self::assertSame('https://localhost/blog/yag-degisimi-2', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('Yag Degisimi | Efe Otomotiv Adana', $crawler->filter('head title')->text());
    }

    /**
     * A consumer renders the trail it is given and expects the last step to be the page it is
     * looking at. A BreadcrumbList whose final item is a different page is discarded outright
     * by the major search engines, so this asserts the last step, not just that a list exists.
     */
    public function testTheBreadcrumbTrailEndsAtThePageItIsOn(): void
    {
        $category = $this->publishedCategory('Frenler', 'frenler-kirinti');
        $this->publishedProduct('SEO-R-10', 'Balata', 'balata-kirinti', 4_400, 2, $category);
        $this->entityManager->flush();

        foreach (['/kategori/frenler-kirinti' => 'Frenler', '/urun/balata-kirinti' => 'Balata'] as $uri => $expectedLast) {
            $crawler = $this->client->request('GET', $uri);
            self::assertResponseIsSuccessful($uri);

            $items = $this->graphOfType($crawler, 'BreadcrumbList')['itemListElement'];
            $last = end($items);
            self::assertSame($expectedLast, $last['name'], $uri);
            self::assertSame(
                $crawler->filter('link[rel="canonical"]')->attr('href'),
                $last['item'],
                $uri.' — the last crumb must be the page itself, not another address.',
            );
        }
    }

    public function testAnInformationPageIsDescribedFromItsOwnBody(): void
    {
        $page = new InformationPage('Kargo Politikasi', 'kargo-politikasi-2', 'Siparisler 1-2 is gunu icinde kargolanir.');
        $page->setPublished(true);
        $this->entityManager->persist($page);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/bilgi/kargo-politikasi-2');

        self::assertResponseIsSuccessful();
        self::assertSame(
            'Siparisler 1-2 is gunu icinde kargolanir.',
            $crawler->filter('head meta[name="description"]')->attr('content'),
        );
    }

    /**
     * Sent as a header rather than a meta tag, and decided from the route that handled the
     * request. That is deliberate: a meta tag would have to be remembered by every controller
     * that renders a private screen, and the one that forgets fails silently.
     */
    public function testTheAccountAreaCheckoutAndCartAreNoindexFollowEvenForASignedInCustomer(): void
    {
        $customer = new CustomerUser('seo@example.com', 'Efe', 'Yilmaz');
        $customer->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, 'VeryStrong!123'));
        $this->entityManager->persist($customer);
        $this->entityManager->flush();
        $this->client->loginUser($customer);

        foreach (['/hesabim', '/hesabim/siparisler', '/odeme', '/sepet'] as $uri) {
            $this->client->request('GET', $uri);
            self::assertResponseIsSuccessful($uri);
            self::assertResponseHeaderSame('x-robots-tag', 'noindex, follow', $uri);
        }
    }

    public function testTheAdminAreaIsNoindexToo(): void
    {
        $this->client->request('GET', '/admin/login');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('x-robots-tag', 'noindex, follow');
    }

    /**
     * Asserts this phase's own policy, not the absence of a header.
     *
     * Outside production Symfony registers DisallowRobotsIndexingListener, which stamps a
     * bare `noindex` on *every* response as a safety net. The suite runs in that mode, so
     * "has no X-Robots-Tag" would be a claim about Symfony, not about this store's rules.
     * What must be true is that a public address is never marked `noindex, follow` — that
     * directive is this phase's, and it is what would keep a real catalogue out of an index.
     */
    public function testNoPublicPageIsMarkedNoindexFollowByThisStoresPolicy(): void
    {
        $marked = [];
        foreach (['/', '/katalog', '/markalar', '/blog', '/bilgi', '/robots.txt'] as $uri) {
            $this->client->request('GET', $uri);
            self::assertResponseIsSuccessful($uri);
            if ('noindex, follow' === $this->client->getResponse()->headers->get('X-Robots-Tag')) {
                $marked[] = $uri;
            }
        }

        self::assertSame([], $marked, 'These public addresses are marked noindex, follow: '.implode(', ', $marked));
    }

    public function testTheSitemapIsExplicitlyNoindexButAStorefrontPageIsNot(): void
    {
        $this->client->request('GET', '/sitemap.xml');
        self::assertResponseHeaderSame('x-robots-tag', 'noindex, follow');

        $this->client->request('GET', '/');
        self::assertNotSame('noindex, follow', $this->client->getResponse()->headers->get('X-Robots-Tag'));
    }

    public function testNoStorefrontPageEverPublishesTheB2bFeedsOwnAddress(): void
    {
        $this->publishedProduct('SEO-R-8', 'B2B Urun', 'b2b-urun-2', 7_700, 2);
        $category = $this->publishedCategory('Filtre', 'filtre');
        $this->entityManager->flush();

        foreach (['/', '/urun/b2b-urun-2', '/kategori/filtre', '/katalog'] as $uri) {
            $this->client->request('GET', $uri);
            self::assertResponseIsSuccessful($uri);
            $html = (string) $this->client->getResponse()->getContent();
            self::assertStringNotContainsString('efeotoyedekparca', $html, $uri);
            self::assertStringNotContainsString('b2b.efeotoyedekparca.com.tr', $html, $uri);
        }
    }

    public function testTheCanonicalIsNeverTakenFromTheRequestHost(): void
    {
        $this->client->request('GET', '/', server: ['HTTP_HOST' => 'attacker.example']);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            'attacker.example',
            (string) $this->client->getResponse()->getContent(),
        );
        self::assertSame('https://localhost/', $this->client->getCrawler()->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testTheStructuredDataCannotBeBrokenOutOfByAProductName(): void
    {
        $hostile = '</script><script>alert(1)</script>';
        $this->publishedProduct('SEO-R-9', $hostile, 'kirli-ad-2', 3_300, 1);

        $crawler = $this->client->request('GET', '/urun/kirli-ad-2');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        // The name must not be able to terminate the <script> element that carries it, and
        // must not become an executable tag in the page.
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        // It must still be the name the customer sees and the graph the crawler reads.
        self::assertStringContainsString('&lt;/script&gt;', $html);
        self::assertSame($hostile, $this->productGraph($crawler)['name']);
    }

    /**
     * @return array<string, mixed>
     */
    private function graphOfType(Crawler $crawler, string $type): array
    {
        foreach ($this->jsonLd($crawler) as $graph) {
            if (($graph['@type'] ?? null) === $type) {
                return $graph;
            }
        }

        self::fail(sprintf('No JSON-LD graph of type %s was rendered.', $type));
    }

    /** @return array<string, mixed> */
    private function productGraph(Crawler $crawler): array
    {
        return $this->graphOfType($crawler, 'Product');
    }

    /** @return list<array<string, mixed>> */
    private function jsonLd(Crawler $crawler): array
    {
        $graphs = [];
        foreach ($crawler->filter('script[type="application/ld+json"]') as $node) {
            $graphs[] = json_decode((string) $node->textContent, true, flags: \JSON_THROW_ON_ERROR);
        }

        return $graphs;
    }

    private function product(Product $product, Category $category): void
    {
        $product->addCategory($category);
        $this->entityManager->flush();
    }

    private function publishedCategory(string $name, string $slug): Category
    {
        $category = new Category($name, $slug);
        $category->publish();
        $this->entityManager->persist($category);

        return $category;
    }

    private function publishedProduct(string $sku, string $name, string $slug, ?int $priceMinor, int $quantity, ?Category $category = null): Product
    {
        $product = new Product($sku, $name, $slug);
        $product->publish();
        if (null !== $category) {
            $product->addCategory($category);
        }
        $this->entityManager->persist($product);
        if (null !== $priceMinor) {
            $this->entityManager->persist(new ProductPrice(
                $product,
                Money::ofMinor($priceMinor, 'TRY'),
                TaxCategory::of('replacement-part'),
                TaxRate::fromBasisPoints(2_000),
            ));
        }
        $this->entityManager->persist(new ProductInventory($product, $quantity, true));
        $this->entityManager->flush();

        return $product;
    }
}
