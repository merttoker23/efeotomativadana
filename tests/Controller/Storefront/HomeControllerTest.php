<?php

namespace App\Tests\Controller\Storefront;

use App\Entity\Catalog\Product;
use App\Entity\Cms\HomeSection;
use App\Entity\Cms\BlogPost;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Module\Cms\HomeSectionType;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class HomeControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
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

    public function testHomeRendersTheAccessibleStorefrontShell(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="tr"]');
        self::assertSelectorTextContains('a.storefront-brand', 'Efe Otomotiv Adana');
        // The shell controller now sits on the body so it can reach both the notice band above the
        // header and the navigation inside it; the band itself is the theme's single `.notice`.
        self::assertSelectorExists('body[data-controller~="storefront-shell"]');
        self::assertSelectorExists('.notice[role="status"]');
        self::assertSame(1, $crawler->filter('.notice')->count(), 'There is exactly one notice band.');
        self::assertSelectorExists('nav[aria-label="Ana navigasyon"]');
        self::assertSelectorExists('button[aria-controls="mobile-navigation"][aria-expanded="false"]');
        self::assertSelectorExists('#mobile-navigation[hidden]');
        self::assertSelectorTextContains('main#main-content h1', 'Otomotiv parçasında güvenilir adresiniz');
        self::assertSelectorExists('footer.site-footer');

        self::assertSame('/', $crawler->filter('a.storefront-brand')->attr('href'));
        self::assertSame('h1', $crawler->filter('main h1, main h2')->first()->nodeName());
    }

    public function testHomepageUsesTheSameBlogCoverAsArchiveAndDetail(): void
    {
        $cover = '/uploads/cms/'.str_repeat('a', 32).'.png';
        $covered = new BlogPost('Kapaklı yazı', 'kapakli-yazi', 'Özet', 'İçerik');
        $covered->setPublished(true);
        $covered->setCoverImagePath($cover);
        $placeholder = new BlogPost('Kapaksız yazı', 'kapaksiz-yazi', 'Özet', 'İçerik');
        $placeholder->setPublished(true);
        $section = new HomeSection(HomeSectionType::BlogFeed, 'Blog', ['limit' => 3]);
        $section->setEnabled(true);
        foreach ([$covered, $placeholder, $section] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $card = $crawler->filter('.blog-card[href="/blog/kapakli-yazi"]');
        self::assertCount(1, $card->filter('.blog-cover img'));
        self::assertSame('/'.$cover, $card->filter('.blog-cover img')->attr('src'));
        self::assertSame('lazy', $card->filter('img')->attr('loading'));
        self::assertSame('async', $card->filter('img')->attr('decoding'));
        self::assertSelectorExists('.blog-card[href="/blog/kapaksiz-yazi"] .blog-cover');
        self::assertSelectorNotExists('.blog-card[href="/blog/kapaksiz-yazi"] img');
        $crawler = $this->client->request('GET', '/blog');
        self::assertSame('/'.$cover, $crawler->filter('.blog-post-card[href="/blog/kapakli-yazi"] img')->attr('src'));
        self::assertSelectorExists('.blog-post-card img[loading="lazy"][decoding="async"]');
        $crawler = $this->client->request('GET', '/blog/kapakli-yazi');
        self::assertSame('/'.$cover, $crawler->filter('.blog-post-hero img')->attr('src'));
        self::assertSelectorExists('.blog-post-hero img[fetchpriority="high"][decoding="async"]');
        self::assertSelectorNotExists('.blog-post-hero img[loading="lazy"]');
    }

    public function testHomeUsesMappedLocalAssetsWithoutStaticThemeLinks(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(0, 'a[href$=".html"], form[action$=".html"]');
        self::assertStringNotContainsString('tema/', $this->client->getResponse()->getContent() ?: '');

        $stylesheetUrl = $this->requiredAttribute($crawler, 'link[data-storefront-stylesheet]', 'href');
        $heroImageUrl = $this->requiredAttribute($crawler, 'img.hero-image', 'src');
        $importMap = json_decode(
            $crawler->filter('script[type="importmap"]')->text(),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($importMap);
        self::assertIsArray($importMap['imports'] ?? null);
        $controllerUrl = $importMap['imports']['/assets/controllers/storefront_shell_controller.js'] ?? null;
        self::assertIsString($controllerUrl);

        $this->assertLocalAssetLoads($this->client, $stylesheetUrl, 'text/css');
        $this->assertLocalAssetLoads($this->client, $heroImageUrl, 'image/svg+xml');
        $this->assertLocalAssetLoads($this->client, $controllerUrl, 'text/javascript');
    }

    /**
     * The homepage resolves every product in a carousel or tab section in one batch. An N+1
     * here is invisible with five products and fatal with five hundred, so the count is compared
     * across two section sizes as well as against a documented ceiling.
     *
     * The ceiling is 12: two batch queries (one per section), the section listing, the navigation
     * categories and brands, the store configuration, and a small fixed set for the session. All of
     * the per-section work is a single query regardless of how many products the section contains.
     */
    public function testProductSectionsUseAConstantNumberOfQueries(): void
    {
        for ($i = 1; $i <= 6; ++$i) {
            $this->sellableProduct(sprintf('HOME-%02d', $i), sprintf('Ana Sayfa Ürünü %02d', $i), sprintf('ana-sayfa-urunu-%02d', $i));
        }
        $first = $this->createProductCarouselSection([
            'ana-sayfa-urunu-01', 'ana-sayfa-urunu-02', 'ana-sayfa-urunu-03',
        ]);
        $second = $this->createProductCarouselSection([
            'ana-sayfa-urunu-04', 'ana-sayfa-urunu-05', 'ana-sayfa-urunu-06',
        ]);
        $this->entityManager->flush();

        $withSixProducts = $this->profileHome();

        for ($i = 7; $i <= 12; ++$i) {
            $this->sellableProduct(sprintf('HOME-%02d', $i), sprintf('Ana Sayfa Ürünü %02d', $i), sprintf('ana-sayfa-urunu-%02d', $i));
        }
        $this->replaceSectionSlugs($first, [
            'ana-sayfa-urunu-01', 'ana-sayfa-urunu-02', 'ana-sayfa-urunu-03',
            'ana-sayfa-urunu-07', 'ana-sayfa-urunu-08', 'ana-sayfa-urunu-09',
        ]);
        $this->replaceSectionSlugs($second, [
            'ana-sayfa-urunu-04', 'ana-sayfa-urunu-05', 'ana-sayfa-urunu-06',
            'ana-sayfa-urunu-10', 'ana-sayfa-urunu-11', 'ana-sayfa-urunu-12',
        ]);
        $this->entityManager->flush();

        $withTwelveProducts = $this->profileHome();

        self::assertSame($withSixProducts, $withTwelveProducts, 'Homepage product sections must not query per product.');
        self::assertLessThanOrEqual(12, $withSixProducts);
    }

    private function requiredAttribute(Crawler $crawler, string $selector, string $attribute): string
    {
        $node = $crawler->filter($selector);
        self::assertCount(1, $node);

        $value = $node->attr($attribute);
        self::assertNotNull($value);

        return $value;
    }

    private function assertLocalAssetLoads(KernelBrowser $client, string $url, string $contentType): void
    {
        self::assertStringStartsWith('/assets/', $url);
        self::assertStringNotContainsString('tema', $url);

        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', $contentType);
    }

    private function sellableProduct(string $sku, string $name, string $slug): Product
    {
        $product = new Product($sku, $name, $slug);
        $product->publish();
        $this->entityManager->persist($product);
        $this->entityManager->persist(new ProductPrice(
            $product,
            Money::ofMinor(10_000, 'TRY'),
            TaxCategory::of('replacement-part'),
            TaxRate::fromBasisPoints(2_000),
        ));
        $this->entityManager->persist(new ProductInventory($product, 5));
        $this->entityManager->flush();

        return $product;
    }

    /** @param list<string> $slugs */
    private function createProductCarouselSection(array $slugs): HomeSection
    {
        $section = new HomeSection(HomeSectionType::ProductCarousel, 'Öne çıkan ürünler', ['slugs' => $slugs]);
        $section->setEnabled(true);
        $section->setSortOrder(1);
        $this->entityManager->persist($section);
        $this->entityManager->flush();

        return $section;
    }

    /** @param list<string> $slugs */
    private function replaceSectionSlugs(HomeSection $section, array $slugs): void
    {
        $section->update($section->title(), null, ['slugs' => $slugs]);
    }

    private function profileHome(): int
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $debugData);
        $debugData->reset();
        $this->client->enableProfiler();

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);
        $database = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $database);

        return $database->getQueryCount();
    }
}
