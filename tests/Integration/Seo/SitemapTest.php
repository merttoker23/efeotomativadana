<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Cms\BlogPost;
use App\Entity\Cms\InformationPage;
use App\Entity\Integration\ExternalResourceMapping;
use App\Module\Seo\Sitemap\SitemapIndexBuilder;
use App\Module\Seo\Sitemap\SitemapXml;
use App\Module\Seo\Sitemap\SitemapRepository;
use App\Module\Seo\Sitemap\SitemapSectionBuilder;
use App\Module\Seo\Sitemap\SitemapKind;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A sitemap is a promise to a crawler about which addresses exist. Listing an unpublished
 * record in it is worse than omitting a published one: a crawler that trusts it fetches a 404,
 * and the store's other URLs get crawled less often as a result.
 */
final class SitemapTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private SitemapIndexBuilder $index;
    private SitemapSectionBuilder $sections;
    private SitemapRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $manager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->entityManager = $manager;
        $this->index = self::getContainer()->get(SitemapIndexBuilder::class);
        $this->sections = self::getContainer()->get(SitemapSectionBuilder::class);
        $this->repository = self::getContainer()->get(SitemapRepository::class);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheIndexAlwaysListsTheStaticPagesAndOnlySectionsThatHaveContent(): void
    {
        $xml = $this->index->build();

        self::assertStringContainsString('<sitemapindex', $xml);
        self::assertStringContainsString('https://localhost/sitemap-static.xml', $xml);
        // Nothing published in this transaction, so no catalogue section is advertised at all.
        self::assertStringNotContainsString('sitemap-products-', $xml);
        self::assertStringNotContainsString('sitemap-categories-', $xml);
        self::assertStringNotContainsString('sitemap-brands-', $xml);
        self::assertStringNotContainsString('sitemap-content-', $xml);
    }

    public function testAPublishedProductAppearsInTheProductSectionAndInTheIndex(): void
    {
        $this->publishedProduct('SM-001', 'Yag Filtresi', 'yag-filtresi');
        $this->entityManager->flush();

        $xml = $this->index->build();
        self::assertStringContainsString('https://localhost/sitemap-products-1.xml', $xml);

        $section = $this->sections->build(SitemapKind::Products, 1);
        self::assertStringContainsString('<urlset', $section);
        self::assertStringContainsString('<loc>https://localhost/urun/yag-filtresi</loc>', $section);
    }

    public function testADraftProductIsNeverListedAnywhereInTheSitemap(): void
    {
        $product = new Product('SM-002', 'Taslak Filtre', 'taslak-filtre');
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        self::assertStringNotContainsString('taslak-filtre', $this->index->build());
        self::assertStringNotContainsString('taslak-filtre', $this->sections->build(SitemapKind::Products, 1));
    }

    public function testCategoriesBrandsBlogPostsAndInformationPagesAreEachListedWhenPublished(): void
    {
        $category = new Category('Frenler', 'frenler');
        $category->publish();
        $this->entityManager->persist($category);
        $brand = new Brand('Bosch', 'bosch');
        $brand->publish();
        $this->entityManager->persist($brand);
        $post = new BlogPost('Yağ Değişimi', 'yag-degisi', 'Özet', 'Gövde');
        $post->setPublished(true);
        $this->entityManager->persist($post);
        $page = new InformationPage('Kargo', 'kargo', 'Kargo bilgileri');
        $page->setPublished(true);
        $this->entityManager->persist($page);
        $this->entityManager->flush();

        self::assertStringContainsString('https://localhost/kategori/frenler', $this->sections->build(SitemapKind::Categories, 1));
        self::assertStringContainsString('https://localhost/marka/bosch', $this->sections->build(SitemapKind::Brands, 1));
        self::assertStringContainsString('https://localhost/blog/yag-degisi', $this->sections->build(SitemapKind::Content, 1));
        self::assertStringContainsString('https://localhost/bilgi/kargo', $this->sections->build(SitemapKind::Content, 1));
    }

    public function testAnUnpublishedBlogPostOrPageIsExcluded(): void
    {
        $post = new BlogPost('Gizli Yazı', 'gizli-yazi', 'Özet', 'Gövde');
        $this->entityManager->persist($post);
        $page = new InformationPage('Gizli Sayfa', 'gizli-sayfa', 'Gövde');
        $this->entityManager->persist($page);        $this->entityManager->flush();

        $section = $this->sections->build(SitemapKind::Content, 1);
        self::assertStringNotContainsString('gizli-yazi', $section);
        self::assertStringNotContainsString('gizli-sayfa', $section);
    }

    public function testNoAccountCheckoutOrAdminAddressEverAppearsInAnySitemap(): void
    {
        $this->publishedProduct('SM-003', 'Ürün', 'urun-3');
        $this->entityManager->flush();

        $everything = $this->index->build().$this->sections->build(SitemapKind::Static, 1)
            .$this->sections->build(SitemapKind::Products, 1);

        foreach (['/hesabim', '/odeme', '/siparis', '/admin', '/sepet', '/karsilastir', '/istek-listem'] as $private) {
            self::assertStringNotContainsString($private, $everything);
        }
    }

    /**
     * Every address the store publishes must be on the store's own domain.
     *
     * This is the invariant that actually forbids a B2B feed URL, an image host, a CDN or any
     * other outside address from leaking into a sitemap, and unlike a search for one supplier's
     * hostname it fails for every one of them. It is asserted over every section kind.
     */
    public function testEveryPublishedAddressIsOnThisStoresOwnDomain(): void
    {
        $category = new Category('Frenler', 'frenler-host');
        $category->publish();
        $this->entityManager->persist($category);
        $brand = new Brand('Bosch', 'bosch-host');
        $brand->publish();
        $this->entityManager->persist($brand);
        $this->publishedPage(new BlogPost('Blog', 'blog-host', 'Özet', 'Gövde'));
        $this->publishedPage(new InformationPage('Sayfa', 'sayfa-host', 'Gövde'));
        $this->publishedProduct('SM-003', 'Ürün', 'urun-3');
        $this->entityManager->flush();

        $documents = [
            $this->index->build(),
            $this->sections->build(SitemapKind::Static, 1),
            $this->sections->build(SitemapKind::Products, 1),
            $this->sections->build(SitemapKind::Categories, 1),
            $this->sections->build(SitemapKind::Brands, 1),
            $this->sections->build(SitemapKind::Content, 1),
        ];

        $foreign = [];
        foreach ($documents as $xml) {
            $document = simplexml_load_string($xml);
            self::assertInstanceOf(\SimpleXMLElement::class, $document);
            foreach ($document->xpath('//*[local-name()="loc"]') ?: [] as $loc) {
                $host = parse_url((string) $loc, \PHP_URL_HOST);
                if ('localhost' !== $host) {
                    $foreign[] = (string) $loc;
                }
            }
        }

        self::assertSame([], $foreign, 'A sitemap must only ever list this store\'s own addresses.');
    }

    public function testTheStaticSectionListsOnlyThePublicLandingPages(): void
    {
        $section = $this->sections->build(SitemapKind::Static, 1);

        foreach ([
            'https://localhost/',
            'https://localhost/katalog',
            'https://localhost/markalar',
            'https://localhost/blog',
            'https://localhost/bilgi',
        ] as $expected) {
            self::assertStringContainsString('<loc>'.$expected.'</loc>', $section);
        }
    }

    public function testProductsAreChunkedSoOneEnormousFileIsNeverGenerated(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->publishedProduct(sprintf('SM-CH-%03d', $i), 'Parça '.$i, 'parca-'.$i);
        }
        $this->entityManager->flush();

        // The page size is a configured value so this is provable without 1,000 fixtures.
        self::assertSame(3, $this->repository->chunkCount(SitemapKind::Products, 2));

        $first = $this->sections->build(SitemapKind::Products, 1, 2);
        self::assertStringContainsString('parca-1', $first);
        self::assertStringContainsString('parca-2', $first);
        self::assertStringNotContainsString('parca-3', $first);

        $third = $this->sections->build(SitemapKind::Products, 3, 2);
        self::assertStringContainsString('parca-5', $third);
        self::assertStringNotContainsString('parca-1', $third);

        self::assertStringContainsString('sitemap-products-3.xml', $this->index->build(pageSize: 2));
    }

    public function testAPageBeyondTheLastChunkIsNotGenerated(): void
    {
        $this->publishedProduct('SM-004', 'Tek', 'tek-urun');
        $this->entityManager->flush();

        self::assertNull($this->sections->buildOrNull(SitemapKind::Products, 2, 1));
        self::assertNotNull($this->sections->buildOrNull(SitemapKind::Products, 1, 1));
    }

    /**
     * Exercises the escaping itself.
     *
     * A sitemap is built from slugs, and every slug is already restricted to `[a-z0-9-]` by the
     * entity, so a hostile product NAME never reaches the XML and asserting on one proves
     * nothing. The renderer is handed a hostile address directly so that deleting
     * `SitemapXml::escape()` makes this fail.
     */
    public function testTheRendererEscapesAnAddressThatCouldOtherwiseBreakTheDocument(): void
    {
        $xml = SitemapXml::urlSet([
            ['loc' => 'https://localhost/urun/a&b<c>"d"', 'lastmod' => null],
        ]);

        self::assertTrue(simplexml_load_string($xml) instanceof \SimpleXMLElement, 'The urlset must be well-formed XML.');
        self::assertStringNotContainsString('<c>', $xml);
        self::assertSame(
            'https://localhost/urun/a&b<c>"d"',
            (string) simplexml_load_string($xml)->url->loc,
            'Escaping must not change the value a consumer reads back.',
        );
    }

    public function testAPublishedUrlIsEscapedAndWellFormedEndToEnd(): void
    {
        $this->publishedProduct('SM-005', 'Filtre', 'kirli-ad');
        $this->entityManager->flush();

        $section = $this->sections->build(SitemapKind::Products, 1);

        self::assertTrue(simplexml_load_string($section) instanceof \SimpleXMLElement, 'The urlset must be well-formed XML.');
        self::assertStringContainsString('<loc>https://localhost/urun/kirli-ad</loc>', $section);
    }

    public function testAProductThatArrivedFromTheB2BFeedIsPublishedUnderThisStoresOwnRoute(): void
    {
        $product = $this->publishedProduct('SM-006', 'B2B Ürün', 'b2b-urun');
        $this->entityManager->flush();
        // The record really is a B2B import. Its public address is still this store's route.
        $this->entityManager->persist(ExternalResourceMapping::product(
            'efe',
            'SM-006',
            (int) $product->id(),
            null,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
        ));
        $this->entityManager->flush();

        $section = $this->sections->build(SitemapKind::Products, 1);

        self::assertStringContainsString('https://localhost/urun/b2b-urun', $section);
        self::assertStringNotContainsString('efeotoyedekparca', $section);
    }

    /**
     * Blog posts and information pages share one section, so the chunking has to partition
     * across the two sources. Applying LIMIT/OFFSET to each independently would let one file
     * hold twice the declared page size and would advertise a final, empty chunk.
     */
    public function testTheContentSectionIsChunkedAcrossBothOfItsSources(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $this->publishedPage(new BlogPost('Blog '.$i, 'blog-c'.$i, 'Özet', 'Gövde'));
            $this->publishedPage(new InformationPage('Sayfa '.$i, 'sayfa-c'.$i, 'Gövde'));
        }
        $this->entityManager->flush();

        self::assertSame(3, $this->repository->chunkCount(SitemapKind::Content, 2));

        $first = $this->locsIn($this->sections->build(SitemapKind::Content, 1, 2));
        $second = $this->locsIn($this->sections->build(SitemapKind::Content, 2, 2));
        $third = $this->locsIn($this->sections->build(SitemapKind::Content, 3, 2));

        self::assertCount(2, $first);
        self::assertCount(2, $second);
        self::assertCount(2, $third);

        // No URL may appear twice and none may be missing: a wrong partition duplicates or
        // drops rows, which is the whole failure mode being guarded here.
        self::assertCount(6, array_unique([...$first, ...$second, ...$third]));
    }

    public function testAnAdvertisedContentChunkIsNeverEmpty(): void
    {
        for ($i = 1; $i <= 2; ++$i) {
            $this->publishedPage(new BlogPost('Blog '.$i, 'blog-d'.$i, 'Özet', 'Gövde'));
        }
        $this->entityManager->flush();

        $chunks = $this->repository->chunkCount(SitemapKind::Content, 2);
        self::assertSame(1, $chunks);

        for ($chunk = 1; $chunk <= $chunks; ++$chunk) {
            self::assertNotSame([], $this->locsIn($this->sections->build(SitemapKind::Content, $chunk, 2)));
        }
    }

    /** @return list<string> */
    private function locsIn(string $xml): array
    {
        $document = simplexml_load_string($xml);
        self::assertInstanceOf(\SimpleXMLElement::class, $document);

        return array_map(
            static fn (\SimpleXMLElement $url): string => (string) $url->loc,
            iterator_to_array($document->url, false),
        );
    }

    private function publishedPage(BlogPost|InformationPage $entity): void
    {
        $entity->setPublished(true);
        $this->entityManager->persist($entity);
    }

    private function publishedProduct(string $sku, string $name, string $slug): Product
    {
        $product = new Product($sku, $name, $slug);
        $product->publish();
        $this->entityManager->persist($product);

        return $product;
    }
}
