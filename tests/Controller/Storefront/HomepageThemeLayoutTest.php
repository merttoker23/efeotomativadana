<?php

declare(strict_types=1);

namespace App\Tests\Controller\Storefront;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Cms\BlogPost;
use App\Entity\Cms\HomeSection;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Module\Cms\HomeSectionType;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The homepage is the theme's page, arranged the way the theme arranges it.
 *
 * Rendering the same modules as a stack of full-width sections would keep every bit of the data
 * and still be a different page, so the groupings that carry the theme are asserted here as
 * structure: the category panel, the hero slider and the top-sellers column share one row, and the
 * testimonials share a row with the blog feed. Each module's own markup is asserted too, because
 * "the hero is a slider" is a claim about classes and a working controller, not about a heading.
 */
final class HomepageThemeLayoutTest extends WebTestCase
{
    private Connection $connection;
    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        static::createClient()->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $manager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheCategoryMenuHeroAndTopSellersShareTheThemesUpperRow(): void
    {
        $category = $this->category('Fren', 'fren');
        $first = $this->product('THEME-01', 'Balata', 'balata');
        $second = $this->product('THEME-02', 'Disk', 'disk');
        $third = $this->product('THEME-03', 'Balyat', 'balyat');
        $image = $this->cmsImage();

        $this->section(HomeSectionType::CategoryMenu, 'Kategoriler', ['slugs' => [$category->slug()]], 10);
        $this->section(HomeSectionType::HeroSlider, 'Kampanya', ['slides' => [
            ['title' => 'Yaza özel', 'description' => 'Yedek parça fırsatları', 'image' => $image, 'link' => '/yeni/katalog'],
            ['title' => 'Sezon sonu', 'description' => 'Stoktan hızlı çıkış', 'image' => $image, 'link' => '/yeni/katalog'],
        ]], 20);
        $this->section(HomeSectionType::ProductCarousel, 'Çok satanlar', ['slugs' => [$first->slug(), $second->slug(), $third->slug()]], 30);

        $crawler = $this->home();

        // The three modules are columns of one band, not three stacked blocks.
        self::assertSame(1, $crawler->filter('.home-top')->count());
        $top = $crawler->filter('.home-top');
        self::assertStringContainsString('home-top-3', (string) $top->attr('class'));
        foreach (['.category-panel', '.hero', '.top-sellers'] as $column) {
            self::assertTrue(
                $top->filter($column)->getNode(0)->parentNode->isSameNode($top->getNode(0)),
                $column.' is not a column of the upper band.',
            );
        }
        self::assertSame(1, $top->filter('.category-panel .cat-row')->count());
        self::assertSame(3, $top->filter('.top-sellers .seller')->count());
        self::assertSame(1, $top->filter('.hero .hero-slide.active')->count());
    }

    public function testTheHeroIsAWorkingSliderRatherThanAStackOfPictures(): void
    {
        $image = $this->cmsImage();
        $this->section(HomeSectionType::HeroSlider, 'Kampanya', ['slides' => [
            ['title' => 'İlk kare', 'description' => 'Açıklama bir', 'image' => $image, 'link' => '/yeni/katalog'],
            ['title' => 'İkinci kare', 'description' => 'Açıklama iki', 'image' => $image, 'link' => '/yeni/katalog'],
            ['title' => 'Üçüncü kare', 'description' => 'Açıklama üç', 'image' => $image, 'link' => '/yeni/katalog'],
        ]], 10);

        $crawler = $this->home();
        $hero = $crawler->filter('.hero');

        self::assertSame(1, $hero->count());
        self::assertSame(3, $hero->filter('.hero-slide')->count());
        self::assertSame(1, $hero->filter('.hero-slide.active')->count());
        self::assertSame(1, $hero->filter('.hero-slide.active .hero-title')->count());
        // A hidden slide is hidden from assistive technology as well, or the visitor would be
        // read three headlines where one is showing.
        self::assertSame(2, $hero->filter('.hero-slide[aria-hidden="true"]')->count());
        self::assertSame('carousel', $hero->attr('aria-roledescription'));
        self::assertSame('home-slider', $hero->attr('data-controller'));
        self::assertSame(1, $hero->filter('.slider-controls .slider-btn[data-action="home-slider#next"]')->count());
        self::assertSame(1, $hero->filter('.slider-controls .slider-btn[data-action="home-slider#previous"]')->count());
        self::assertSame('1/3', trim($hero->filter('.slide-count')->text()));
    }

    public function testProductTabsCarryRealTabPanelsAndTabBehaviour(): void
    {
        $first = $this->product('TAB-01', 'Filtre', 'filtre');
        $second = $this->product('TAB-02', 'Yağ', 'yag');

        $this->section(HomeSectionType::ProductTabs, 'Ürünler', ['tabs' => [
            ['title' => 'Çok satanlar', 'slugs' => [$first->slug()]],
            ['title' => 'Yeni gelenler', 'slugs' => [$second->slug()]],
        ]], 10);

        $crawler = $this->home();
        $tabs = $crawler->filter('.top-products-tabs');

        self::assertSame(1, $tabs->count());
        self::assertSame('tablist', $tabs->attr('role'));
        self::assertSame('home-tabs', $tabs->attr('data-controller'));
        self::assertSame(2, $tabs->filter('.tab-btn')->count());

        foreach (['0' => 'true', '1' => 'false'] as $position => $selected) {
            $tab = $tabs->filter('#home-tabs-1-tab-'.$position);
            self::assertSame(1, $tab->count());
            self::assertSame('tab', $tab->attr('role'));
            self::assertSame($selected, $tab->attr('aria-selected'));
            self::assertSame('home-tabs-1-panel-'.$position, $tab->attr('aria-controls'));
            // One stop for the whole strip, arrows move within it.
            self::assertSame('0' === (string) $position ? '0' : '-1', $tab->attr('tabindex'));
        }

        $panels = $crawler->filter('.section-card [role="tabpanel"]');
        self::assertSame(2, $panels->count());
        self::assertSame(1, $crawler->filter('[role="tabpanel"]:not([hidden])')->count());
        self::assertSame(1, $crawler->filter('#home-tabs-1-panel-0:not([hidden])')->count());
        self::assertSame(1, $crawler->filter('#home-tabs-1-panel-1[hidden]')->count());
        self::assertSelectorTextContains('#home-tabs-1-panel-0 .product-name', 'Filtre');
        self::assertSelectorTextContains('#home-tabs-1-panel-1 .product-name', 'Yağ');
    }

    public function testThePromoGridFeaturesBrandShelfAndMarqueeUseTheThemesBlocks(): void
    {
        $image = $this->cmsImage();
        $brand = $this->brand('Brembo', 'brembo');

        $this->section(HomeSectionType::BannerGrid, 'Kampanyalar', ['banners' => [
            ['title' => 'Yaz indirimi', 'image' => $image, 'link' => '/yeni/katalog'],
        ]], 10);
        $this->section(HomeSectionType::Features, 'Güvence', ['features' => [
            ['title' => 'Hızlı kargo', 'description' => 'Aynı gün gönderim'],
        ]], 20);
        $this->section(HomeSectionType::BrandStrip, 'Popüler markalar', ['slugs' => [$brand->slug()]], 30);
        $this->section(HomeSectionType::Marquee, 'Kayan yazı', ['items' => ['Aynı gün teslimat', 'Yetkili servis']], 40);

        $crawler = $this->home();

        self::assertSame(1, $crawler->filter('.promo-grid .promo-card')->count());
        self::assertSame(1, $crawler->filter('.features .feature .feature-ico')->count());
        self::assertSelectorTextContains('.features .feature b', 'Hızlı kargo');
        self::assertSame(1, $crawler->filter('.section-card .brand-strip .brand')->count());
        self::assertSame(2, $crawler->filter('.marquee .marquee-item')->count());
        self::assertSame(1, $crawler->filter('.marquee')->count()); // theme: a single marquee container with item/dot separators
        self::assertSelectorTextContains('.marquee', 'Yetkili servis');
    }

    public function testTheTestimonialsAndBlogFeedShareTheThemesClosingRow(): void
    {
        $post = new BlogPost('Fren bakımı', 'fren-bakimi', 'Fren bakımının adımları.', 'Gövde');
        $this->manager->persist($post);
        $this->manager->flush();
        $this->connection->executeStatement('UPDATE cms_blog_post SET published = 1 WHERE id = ?', [$post->id()]);

        $this->section(HomeSectionType::Testimonials, 'Müşterilerimiz ne diyor', ['quotes' => [
            ['author' => 'Mehmet Y.', 'text' => 'Çok hızlı teslim edildi.'],
        ]], 10);
        $this->section(HomeSectionType::BlogFeed, 'Blog', ['limit' => 3], 20);

        $crawler = $this->home();
        $closing = $crawler->filter('.testimonial-blog');

        self::assertSame(1, $closing->count());
        self::assertSame(1, $closing->filter('.quote .quote-item')->count());
        self::assertSame(1, $closing->filter('.section-card .blog-grid .blog-card')->count());
        self::assertTrue($closing->filter('.quote')->getNode(0)->parentNode->isSameNode($closing->getNode(0)));
        self::assertSelectorTextContains('.testimonial-blog .blog-card h3', 'Fren bakımı');
    }

    public function testASectionWithoutItsThemeSlotFallsBackToAStandaloneBlock(): void
    {
        $product = $this->product('SOLO-01', 'Filtre', 'solo-filtre');
        $other = $this->product('SOLO-02', 'Yağ', 'solo-yag');
        $this->section(HomeSectionType::ProductCarousel, 'Çok satanlar', ['slugs' => [$product->slug()]], 10);
        $this->section(HomeSectionType::ProductCarousel, 'Yeni gelenler', ['slugs' => [$other->slug()]], 20);

        $crawler = $this->home();

        // The first product carousel fills the theme's top-sellers column; the second stays in the
        // flow as the theme's own product grid rather than being swallowed by the band.
        self::assertSame(1, $crawler->filter('.top-sellers .seller')->count());
        self::assertSelectorTextContains('.top-sellers .seller-name', 'Filtre');
        $block = $crawler->filter('.home-section.section-card .product-grid');
        self::assertSame(1, $block->count());
        self::assertSelectorTextContains('.home-section.section-card .section-head h2', 'Yeni gelenler');
        self::assertSelectorTextContains('.home-section.section-card .product-grid .product-name', 'Yağ');
        self::assertSame(1, $crawler->filter('.home-section.section-card .see-all')->count());
    }

    public function testTheAnnouncementBarUsesTheThemesNoticeBand(): void
    {
        $this->section(HomeSectionType::AnnouncementBar, 'Duyuru', ['text' => 'Yaz sezonunda ücretsiz kargo.'], 10);

        $crawler = $this->home();

        self::assertSame(1, $crawler->filter('.notice[role="status"] .notice-text')->count());
        self::assertSelectorTextContains('.notice .notice-text', 'ücretsiz kargo');
    }

    /**
     * The closing band must not be rendered when neither of its two modules exists, and the upper
     * band likewise — an empty grid would still be a visible frame around nothing.
     */
    public function testABandIsNotRenderedWhenTheAdministratorHasNotFilledIt(): void
    {
        $this->section(HomeSectionType::Marquee, 'Kayan yazı', ['items' => ['Tek bant']], 10);

        $crawler = $this->home();

        self::assertSame(0, $crawler->filter('.home-top')->count());
        self::assertSame(0, $crawler->filter('.testimonial-blog')->count());
        self::assertSame(1, $crawler->filter('.marquee')->count());
    }

    private function home(): Crawler
    {
        $crawler = static::getClient()->request('GET', '/yeni/');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function category(string $name, string $slug): Category
    {
        $category = new Category($name, $slug);
        $category->publish();
        $this->manager->persist($category);
        $this->manager->flush();

        return $category;
    }

    private function brand(string $name, string $slug): Brand
    {
        $brand = new Brand($name, $slug);
        $brand->publish();
        $this->manager->persist($brand);
        $this->manager->flush();

        return $brand;
    }

    private function product(string $sku, string $name, string $slug): Product
    {
        $product = new Product($sku, $name, $slug);
        $product->publish();
        $this->manager->persist($product);
        $this->manager->persist(new ProductPrice(
            $product,
            Money::ofMinor(10_000, 'TRY'),
            TaxCategory::of('replacement-part'),
            TaxRate::fromBasisPoints(2_000),
        ));
        $this->manager->persist(new ProductInventory($product, 5));
        $this->manager->flush();

        return $product;
    }

    /**
     * A path in the naming `CmsMediaStorage` itself mints, so the section passes the domain's own
     * image rule without a file being uploaded for a layout test.
     */
    private function cmsImage(): string
    {
        return '/uploads/cms/'.str_repeat('a', 32).'.jpg';
    }

    /** @param array<string, mixed> $configuration */
    private function section(HomeSectionType $type, string $title, array $configuration, int $sortOrder): HomeSection
    {
        $section = new HomeSection($type, $title, $configuration);
        $section->setEnabled(true);
        $section->setSortOrder($sortOrder);
        $this->manager->persist($section);
        $this->manager->flush();

        return $section;
    }
}
