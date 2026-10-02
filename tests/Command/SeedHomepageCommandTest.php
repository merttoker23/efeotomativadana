<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Cms\BlogPost;
use App\Entity\Cms\HomeSection;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Module\Catalog\CatalogSource;
use App\Module\Cms\CmsMediaStorage;
use App\Module\Cms\HomeSectionType;
use App\Module\Cms\SectionConfiguration;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The homepage seed fills an empty CMS and is refused a store that already has content.
 *
 * What this command is for is not that it can write twelve rows; it is that it can do so without
 * touching anything else. Most of what is asserted here is therefore a negative — no new catalogue
 * record, no draft ever referenced, no change at all once a section exists — because those are how
 * a convenience command quietly becomes a data-loss command.
 */
final class SeedHomepageCommandTest extends WebTestCase
{
    /** The theme's twelve modules, in the order `tema/index.html` draws them. */
    private const THEME_ORDER = [
        'announcement_bar',
        'category_menu',
        'hero_slider',
        'product_carousel',
        'banner_grid',
        'product_carousel',
        'features',
        'brand_strip',
        'product_tabs',
        'marquee',
        'testimonials',
        'blog_feed',
    ];

    private Connection $connection;
    private EntityManagerInterface $manager;
    private CommandTester $tester;

    /** @var list<string> media paths that existed before this test ran */
    private array $mediaBefore = [];

    protected function setUp(): void
    {
        static::createClient()->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $manager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $this->mediaBefore = array_column($this->media()->library(1000), 'path');
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        // The command installs real files; a test must not leave them in the shared media library.
        foreach ($this->media()->library(1000) as $image) {
            if (!in_array($image['path'], $this->mediaBefore, true)) {
                $this->media()->removeInstalled($image['path']);
            }
        }

        parent::tearDown();
    }

    public function testAnEmptyHomepageIsFilledInTheThemesOrderWithValidEnabledSections(): void
    {
        $this->catalogue(12);
        $this->publishedPost();

        self::assertSame(Command::SUCCESS, $this->seed());

        $sections = $this->sections();
        self::assertCount(12, $sections);
        self::assertSame(self::THEME_ORDER, $this->types($sections));
        self::assertSame(
            [10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 110, 120],
            array_map(static fn (HomeSection $section): int => $section->sortOrder(), $sections),
        );

        foreach ($sections as $section) {
            self::assertTrue($section->enabled(), 'Every seeded section is visible without an administrator enabling it.');
            // The domain refused to build any of these; re-running the rule proves the stored
            // configuration is the one an administrator's own save would have produced.
            self::assertSame(
                $section->configuration(),
                SectionConfiguration::validate($section->type(), $section->configuration()),
            );
        }
    }

    public function testSeedingCreatesNoCatalogueRecordAndChangesNone(): void
    {
        $this->catalogue(12);
        $before = $this->catalogueCounts();

        self::assertSame(Command::SUCCESS, $this->seed());

        self::assertSame($before, $this->catalogueCounts());
    }

    /**
     * A store that already has one section of any kind is left exactly as it is.
     *
     * There is no `--replace`, so the only way this command could destroy content would be a
     * future option or a bug; this is the assertion that would notice.
     */
    public function testAPopulatedHomepageIsLeftUntouched(): void
    {
        $this->catalogue(12);
        $existing = new HomeSection(HomeSectionType::AnnouncementBar, 'Mağaza duyurusu', ['text' => 'Mağaza duyurusu']);
        $existing->setEnabled(false);
        $existing->setSortOrder(7);
        $this->manager->persist($existing);
        $this->manager->flush();
        $before = $this->sections();

        self::assertSame(Command::SUCCESS, $this->seed());
        self::assertStringContainsString('already contains sections', $this->tester->getDisplay());
        self::assertStringContainsString('Nothing was changed', $this->tester->getDisplay());

        $after = $this->sections();
        self::assertCount(1, $after);
        self::assertSame($before[0]->id(), $after[0]->id());
        self::assertSame('Mağaza duyurusu', $after[0]->title());
        self::assertSame(['text' => 'Mağaza duyurusu'], $after[0]->configuration());
        self::assertFalse($after[0]->enabled(), 'A disabled section must stay disabled.');
        self::assertSame(7, $after[0]->sortOrder(), 'An existing order must not be renumbered.');
    }

    public function testOnlyPublishedCatalogueRecordsAreReferenced(): void
    {
        $this->catalogue(12);
        $this->category('Taslak Kategori', 'taslak-kategori', false);
        $this->brand('Taslak Marka', 'taslak-marka', false);
        $this->product('DRAFT-01', 'Taslak Ürün', 'taslak-urun', false, null, null);
        $this->manager->flush();

        self::assertSame(Command::SUCCESS, $this->seed());

        $referenced = json_encode($this->configurations());
        self::assertIsString($referenced);
        self::assertStringNotContainsString('taslak-urun', $referenced);
        self::assertStringNotContainsString('taslak-kategori', $referenced);
        self::assertStringNotContainsString('taslak-marka', $referenced);
        self::assertStringContainsString('urun-1', $referenced);
    }

    /**
     * Three products is fewer than the ten the seed draws from. It uses all three, splits them
     * between the two carousels and fails nothing; what it cannot fill, it leaves out.
     */
    public function testASmallCatalogueIsUsedAsItIsRatherThanRefused(): void
    {
        $this->catalogue(3);

        self::assertSame(Command::SUCCESS, $this->seed());

        $sections = $this->sections();
        // Everything except the blog feed, which has no post to feed on.
        self::assertCount(11, $sections);
        self::assertNotContains('blog_feed', $this->types($sections));
        self::assertStringContainsString('Blog akışı', $this->tester->getDisplay());

        $carousels = array_values(array_filter($sections, static fn (HomeSection $s): bool => HomeSectionType::ProductCarousel === $s->type()));
        self::assertCount(2, $carousels);
        self::assertCount(2, $carousels[0]->configuration()['slugs']);
        self::assertCount(1, $carousels[1]->configuration()['slugs']);

        $tabs = $sections[8]->configuration()['tabs'];
        self::assertCount(1, $tabs, 'A tab with no products is left out rather than offered empty.');
        self::assertCount(3, $tabs[0]['slugs']);

        self::assertCount(3, $sections[1]->configuration()['slugs'], 'All three published categories are used.');
        self::assertCount(3, $sections[7]->configuration()['slugs'], 'All three published brands are used.');

        static::getClient()->request('GET', '/yeni/');
        self::assertResponseIsSuccessful();
    }

    /**
     * Nothing published at all: the six sections that reference no catalogue record are still
     * written, the six that would render an empty frame are skipped and reported, and no
     * placeholder catalogue is invented to fill them.
     */
    public function testAnEmptyCatalogueSkipsEveryCatalogueBackedSection(): void
    {
        self::assertSame(Command::SUCCESS, $this->seed());

        self::assertSame([
            'announcement_bar',
            'hero_slider',
            'banner_grid',
            'features',
            'marquee',
            'testimonials',
        ], $this->types($this->sections()));

        foreach (['Kategori menüsü', 'Çok satanlar', 'Marka şeridi', 'Ürün sekmeleri', 'Yeni gelenler karuseli', 'Blog akışı'] as $label) {
            self::assertStringContainsString($label, $this->tester->getDisplay());
        }

        $crawler = static::getClient()->request('GET', '/yeni/');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.notice .notice-text')->count());
        self::assertSame(3, $crawler->filter('.hero .hero-slide')->count());
        self::assertSame(4, $crawler->filter('.promo-grid .promo-card')->count());
        self::assertSame(0, $crawler->filter('.top-sellers')->count());
        self::assertSame(0, $crawler->filter('.brand-strip')->count());
        self::assertSame([0, 0, 0, 0, 0], array_values($this->catalogueCounts()));
    }

    public function testTheSeededHomepageRendersInTheThemesBandsAndOrder(): void
    {
        $this->catalogue(12);
        $this->publishedPost();

        self::assertSame(Command::SUCCESS, $this->seed());

        $crawler = static::getClient()->request('GET', '/yeni/');
        self::assertResponseIsSuccessful();

        // The theme's bands, in the theme's order: notice, the three-column upper row, the promo
        // grid, the new-arrivals grid, features, brands, tabs, marquee, and the closing row.
        foreach ([
            '.notice .notice-text',
            '.home-top .category-panel .cat-row',
            '.home-top .hero .hero-slide',
            '.home-top .top-sellers .seller',
            '.promo-grid .promo-card',
            '.home-section.section-card .product-grid',
            '.features .feature',
            '.brand-strip .brand',
            '.top-products-tabs .tab-btn',
            '.marquee .marquee-item',
            '.testimonial-blog .quote .quote-item',
            '.testimonial-blog .blog-card',
        ] as $selector) {
            self::assertGreaterThan(0, $crawler->filter($selector)->count(), $selector.' is missing from the seeded homepage.');
        }

        self::assertSame(10, $crawler->filter('.brand-strip .brand')->count());
        self::assertSame(3, $crawler->filter('.top-products-tabs .tab-btn')->count());
        self::assertSame(5, $crawler->filter('.top-sellers .seller')->count());

        // Every seeded image is a real file in the CMS media library, not a reference to nothing.
        foreach ($crawler->filter('.hero-photo, .promo-card img')->each(static fn ($node): string => (string) $node->attr('src')) as $source) {
            self::assertMatchesRegularExpression('~/uploads/cms/[a-f0-9]{32}\.jpg$~', $source);
        }
    }

    private function seed(): int
    {
        $this->tester = new CommandTester((new Application(self::$kernel))->find('app:cms:seed-homepage'));

        return $this->tester->execute([]);
    }

    /**
     * @param list<HomeSection> $sections
     *
     * @return list<string>
     */
    private function types(array $sections): array
    {
        return array_map(static fn (HomeSection $section): string => $section->type()->value, $sections);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function configurations(): array
    {
        return array_map(static fn (HomeSection $section): array => $section->configuration(), $this->sections());
    }

    /** @return list<HomeSection> */
    private function sections(): array
    {
        return $this->manager->getRepository(HomeSection::class)->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    private function media(): CmsMediaStorage
    {
        $media = self::getContainer()->get(CmsMediaStorage::class);
        self::assertInstanceOf(CmsMediaStorage::class, $media);

        return $media;
    }

    /** @return array<string, int> */
    private function catalogueCounts(): array
    {
        $counts = [];
        foreach (['catalog_product', 'catalog_category', 'catalog_brand', 'commerce_product_price', 'commerce_product_inventory'] as $table) {
            $counts[$table] = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table);
        }

        return $counts;
    }

    /**
     * @param int $products how many published products, each with a price, stock, category and brand
     */
    private function catalogue(int $products): void
    {
        for ($index = 1; $index <= $products; ++$index) {
            $category = $this->category('Kategori '.$index, 'kategori-'.$index);
            $brand = $this->brand('Marka '.$index, 'marka-'.$index);
            $this->product(sprintf('SEED-%03d', $index), 'Ürün '.$index, 'urun-'.$index, true, $category, $brand);
        }
        $this->manager->flush();
    }

    private function publishedPost(): void
    {
        $post = new BlogPost('Fren bakımı', 'fren-bakimi', 'Fren bakımının adımları.', 'Gövde');
        $post->setPublished(true);
        $this->manager->persist($post);
        $this->manager->flush();
    }

    private function category(string $name, string $slug, bool $publish = true): Category
    {
        $category = new Category($name, $slug);
        if ($publish) {
            $category->publish();
        }
        $this->manager->persist($category);

        return $category;
    }

    private function brand(string $name, string $slug, bool $publish = true): Brand
    {
        $brand = new Brand($name, $slug);
        if ($publish) {
            $brand->publish();
        }
        $this->manager->persist($brand);

        return $brand;
    }

    private function product(string $sku, string $name, string $slug, bool $publish, ?Category $category, ?Brand $brand): Product
    {
        $product = new Product($sku, $name, $slug, CatalogSource::Local, $brand);
        if ($category instanceof Category) {
            $product->addCategory($category);
        }
        if ($publish) {
            $product->publish();
            $this->manager->persist(new ProductPrice(
                $product,
                Money::ofMinor(45_000, 'TRY'),
                TaxCategory::of('replacement-part'),
                TaxRate::fromBasisPoints(2_000),
            ));
            $this->manager->persist(new ProductInventory($product, 5));
        }
        $this->manager->persist($product);

        return $product;
    }
}