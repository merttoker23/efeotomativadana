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
use PHPUnit\Framework\Attributes\DataProvider;
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
 *
 * The last test in this class is the one that would notice a slow drift: it reads
 * `tema/index.html` — the reference the design was taken from — parses the blocks it draws, and
 * compares that order with the blocks this storefront actually renders. It is a real comparison
 * against the source of truth rather than a restatement of the markup written here.
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

        $this->section(HomeSectionType::CategoryMenu, 'Kategoriler', ['slugs' => [$category->slug()]], 10);
        $this->section(HomeSectionType::HeroSlider, 'Kampanya', ['slides' => [
            $this->slide(['title' => 'Yaza özel']),
            $this->slide(['label' => 'Efe Otomotiv', 'title' => 'Doğru parça', 'secondaryText' => 'Kategoriler', 'secondaryLink' => '/yeni/kategoriler']),
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

    /**
     * Every category row has to be a whole row.
     *
     * The panel is 460px tall because the hero beside it is, which is what lines the three columns
     * of the upper band up — and it is seeded with nine categories. A fixed row height only ever
     * fills a fixed panel when the row count divides it exactly, and nine rows of 54.4px is
     * 489.6px, so the ninth row was clipped in half by `overflow: hidden` and the panel looked
     * broken at the bottom of it. The rows now share the panel's height instead.
     *
     * That is a property of the stylesheet and of nothing else: the markup renders nine rows either
     * way, so this reads the stylesheet rather than the response, which is the same reason the
     * geometry test above reads both stylesheets.
     */
    public function testTheCategoryPanelGivesEveryRowAWholeRowToSitIn(): void
    {
        $declarations = $this->desktopDeclarationsOf(dirname(__DIR__, 3).'/assets/styles/storefront.css');

        self::assertSame('flex', $declarations['.category-panel']['display'] ?? null, 'The category panel is no longer a column.');
        self::assertSame('column', $declarations['.category-panel']['flex-direction'] ?? null);
        self::assertArrayHasKey('flex', $declarations['.cat-row'] ?? [], 'A category row no longer grows to share the panel.');
        self::assertArrayNotHasKey('height', $declarations['.cat-row'] ?? [], 'A fixed category row height is where the cut rows came from.');

        // Past the rows the panel can show whole, it scrolls. Clipping is the one thing it must not
        // do, because a clipped row cannot be reached at all.
        self::assertSame('auto', $declarations['.category-panel']['overflow-y'] ?? null, 'The category panel clips the rows it cannot fit.');
    }

    public function testHeroPictureUsesAMobileBannerOrPlaceholderWithoutChangingDesktopImage(): void
    {
        $mobile = '/uploads/cms/'.str_repeat('b', 32).'.webp';
        $this->section(HomeSectionType::HeroSlider, 'Kampanya', ['slides' => [
            $this->slide(['mobileImage' => $mobile]),
            $this->slide(),
        ]], 10);
        $slides = $this->home()->filter('.hero-slide');
        self::assertNull($slides->eq(0)->filter('img.hero-photo')->attr('loading'));
        self::assertSame('high', $slides->eq(0)->filter('img.hero-photo')->attr('fetchpriority'));
        self::assertSame('async', $slides->eq(0)->filter('img.hero-photo')->attr('decoding'));
        self::assertSame('lazy', $slides->eq(1)->filter('img.hero-photo')->attr('loading'));
        self::assertSame('async', $slides->eq(1)->filter('img.hero-photo')->attr('decoding'));
        $media = self::getContainer()->get(\App\Twig\StorefrontMediaExtension::class);
        self::assertSame($media->mediaUrl($mobile), $slides->eq(0)->filter('picture source[media="(max-width: 680px)"]')->attr('srcset'));
        self::assertSame($media->productImageUrl(null), $slides->eq(1)->filter('picture source[media="(max-width: 680px)"]')->attr('srcset'));
        foreach ($slides as $slide) {
            self::assertSame($media->mediaUrl($this->slide()['image']), (new Crawler($slide))->filter('picture img.hero-photo')->attr('src'));
        }
    }

    public function testTheHeroIsAWorkingSliderRatherThanAStackOfPictures(): void
    {
        $this->section(HomeSectionType::HeroSlider, 'Kampanya', ['slides' => [
            $this->slide(['title' => 'İlk kare']),
            $this->slide(['title' => 'İkinci kare']),
            $this->slide(['title' => 'Üçüncü kare']),
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

    /**
     * The reference's three slides are three compositions of one frame, and which composition a
     * slide gets follows from its own content rather than from a fixed "Keşfet" link on all of them.
     */
    public function testEachHeroCompositionIsRenderedFromTheSlidesOwnContent(): void
    {
        $this->section(HomeSectionType::HeroSlider, 'Kampanya', ['slides' => [
            $this->slide(['label' => 'Yeni Ürünler', 'title' => 'Pilli', 'priceLabel' => 'Şu andan itibaren', 'priceValue' => '2.499,00 TL']),
            $this->slide([
                'label' => 'Efe Otomotiv',
                'title' => 'İki butonlu',
                'primaryText' => 'Hemen başla',
                'primaryLink' => '/yeni/katalog',
                'secondaryText' => 'Kategoriler',
                'secondaryLink' => '/yeni/kategoriler',
            ]),
        ]], 10);

        $crawler = $this->home();
        $slides = $crawler->filter('.hero-slide');

        self::assertSelectorTextContains('.hero-slide.active .hero-label', 'Yeni Ürünler');
        self::assertSame(1, $slides->eq(0)->filter('.price-pill')->count());
        self::assertSelectorTextContains('.hero-slide.active .price-pill span', 'Şu andan itibaren');
        self::assertSelectorTextContains('.hero-slide.active .price-pill strong', '2.499,00 TL');
        self::assertSame(0, $slides->eq(0)->filter('.hero-actions')->count());

        // The second composition: two calls to action, no pill, and the theme's own left-aligned
        // feature frame.
        self::assertSame(0, $slides->eq(1)->filter('.price-pill')->count());
        self::assertSame(1, $slides->eq(1)->filter('.hero-actions .shop-now[href="/yeni/katalog"]')->count());
        self::assertSame(1, $slides->eq(1)->filter('.hero-actions .learn-more[href="/yeni/kategoriler"]')->count());
        self::assertStringContainsString('mobile-feature', (string) $slides->eq(1)->attr('class'));
        self::assertStringContainsString('alt', (string) $slides->eq(1)->attr('class'));

        self::assertSame(0, $crawler->filter('.hero .hero-cta')->count(), 'No fixed per-slide call to action survives.');
    }

    /**
     * A slide may be nothing but a banner, and then the frame's dark margin has nothing to make
     * room for.
     *
     * The theme insets the photograph so a headline has space; on an image-only slide that inset is
     * an empty black band, which is what an administrator uploading their own artwork sees. So the
     * slide is marked, the stylesheet fills the frame with `cover` so a banner of any ratio covers
     * it without being stretched, and the theme's second-slide tint is not applied to a banner
     * whose colours were chosen by whoever uploaded it.
     */
    public function testAnImageOnlySlideFillsTheHeroFrameInItsUploadedColours(): void
    {
        $this->section(HomeSectionType::HeroSlider, 'Kampanya', ['slides' => [
            $this->slide(['label' => '', 'title' => '']),
            $this->slide(['label' => '', 'title' => '']),
            $this->slide(['label' => '', 'title' => '']),
            $this->slide(['title' => 'Metinli kare']),
        ]], 10);

        $crawler = $this->home();
        $slides = $crawler->filter('.hero-slide');

        self::assertSame(3, $slides->filter('.image-only')->count());
        // The second banner is an `.alt` slide, which is the one the theme tints.
        self::assertStringContainsString('alt', (string) $slides->eq(1)->attr('class'));
        self::assertStringContainsString('image-only', (string) $slides->eq(1)->attr('class'));
        self::assertStringNotContainsString('image-only', (string) $slides->eq(3)->attr('class'));
        self::assertSame('Metinli kare', trim($slides->eq(3)->filter('.hero-title')->text()));

        $css = $this->declarationsOf(dirname(__DIR__, 3).'/assets/styles/storefront.css');
        // Three classes against one, so this wins over the inset photograph and over the mobile
        // breakpoint that shrinks it; `inset` is what takes back the `left: 10%` margin.
        self::assertSame('0', $css['.hero-slide.image-only .hero-photo']['inset']);
        self::assertSame('100%', $css['.hero-slide.image-only .hero-photo']['width']);
        self::assertSame('100%', $css['.hero-slide.image-only .hero-photo']['height']);
        self::assertSame('cover', $css['.hero-slide.image-only .hero-photo']['object-fit'], 'A banner is cropped to fill the frame, never stretched.');
        self::assertSame('none', $css['.hero-slide.image-only.alt .hero-photo']['filter']);

        // The theme's own geometry is untouched: a slide that has words keeps the inset photograph.
        self::assertSame('10%', $css['.hero-photo']['left']);
        self::assertSame('82%', $css['.hero-photo']['width']);
        self::assertSame('hue-rotate(22deg) saturate(0.75)', $css['.hero-slide.alt .hero-photo']['filter']);
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
        self::assertSame(2, $crawler->filter('.marquee-group:not([aria-hidden]) .marquee-item:not([aria-hidden])')->count());
        self::assertSame(2, $crawler->filter('.marquee-group')->count());
        self::assertSame(1, $crawler->filter('.marquee-group[aria-hidden="true"]')->count());
        self::assertSame(
            $crawler->filter('.marquee-group')->first()->text(),
            $crawler->filter('.marquee-group')->last()->text(),
            'The ticker loop must use equal content groups, including the trailing separator.',
        );
        self::assertSame(1, $crawler->filter('.marquee')->count()); // theme: a single marquee container with item/dot separators
        self::assertSelectorTextContains('.marquee', 'Yetkili servis');
    }

    /**
     * The theme's split builder: three products on the wider side, one large promotional panel on
     * the narrower one, with the panel's five editable parts all present.
     */
    public function testTheSplitBuilderPairsAProductListWithOneBigPromo(): void
    {
        $first = $this->product('SPLIT-01', 'Balata', 'split-balata');
        $second = $this->product('SPLIT-02', 'Disk', 'split-disk');
        $third = $this->product('SPLIT-03', 'Balyat', 'split-balyat');

        $this->section(HomeSectionType::SplitBuilder, 'Fren aksesuarları', [
            'label' => 'Yetkili Satıcı',
            'headline' => '2.500 TL üzeri ücretsiz kargo',
            'description' => 'Fiyatlar stoklarla sınırlıdır.',
            'cta' => 'Kampanyayı İncele',
            'link' => '/yeni/katalog',
            'slugs' => [$first->slug(), $second->slug(), $third->slug()],
        ], 10);

        $crawler = $this->home();
        $split = $crawler->filter('.split-builder');

        self::assertSame(1, $split->count());
        self::assertSame(3, $split->filter('.section-card .split-builder-grid .product-card')->count());
        self::assertSelectorTextContains('.split-builder .section-head h2', 'Fren aksesuarları');
        self::assertSelectorTextContains('.split-builder .big-promo > span', 'Yetkili Satıcı');
        self::assertSelectorTextContains('.split-builder .big-promo h2', '2.500 TL üzeri ücretsiz kargo');
        self::assertSelectorTextContains('.split-builder .big-promo p', 'stoklarla sınırlıdır');
        self::assertSelectorTextContains('.split-builder .big-promo .big-promo-cta', 'Kampanyayı İncele');
        self::assertSame('/yeni/katalog', $split->filter('.big-promo .big-promo-cta')->attr('href'));
        // The homepage's card, not the catalogue's: the catalogue list keeps its own partial.
        self::assertSame(3, $split->filter('.product-card.home-product-card')->count());
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

    /**
     * A closing row with one of its two panels missing must not leave half a row of blank white
     * behind it, which is what the theme's 1fr / 1.5fr would otherwise do.
     */
    public function testAClosingRowWithOnePanelDoesNotLeaveAHalfWidthHole(): void
    {
        $this->section(HomeSectionType::Testimonials, 'Müşterilerimiz ne diyor', ['quotes' => [
            ['author' => 'Mehmet Y.', 'text' => 'Çok hızlı teslim edildi.'],
        ]], 10);

        $crawler = $this->home();

        self::assertStringContainsString('testimonial-blog-1', (string) $crawler->filter('.testimonial-blog')->attr('class'));
        self::assertSame(0, $crawler->filter('.blog-grid')->count());
        self::assertSame(0, $crawler->filter('.testimonial-blog .blog-card')->count());
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

    /**
     * There is one notice band and it is the theme's: at the very top of the page, above the
     * header, and carrying the CMS announcement's text.
     */
    public function testTheAnnouncementBarUsesTheThemesNoticeBand(): void
    {
        $this->section(HomeSectionType::AnnouncementBar, 'Duyuru', ['text' => 'Yaz sezonunda ücretsiz kargo.'], 10);

        $crawler = $this->home();

        self::assertSame(1, $crawler->filter('.notice[role="status"] .notice-ticker-group[aria-hidden="true"]')->count());
        self::assertSelectorTextContains('.notice .notice-ticker-item', 'ücretsiz kargo');

        // The theme's position: the notice is a child of the body, before the header, not a second
        // strip below the navigation.
        $notice = $crawler->filter('.notice')->getNode(0);
        self::assertSame('body', $notice->parentNode->nodeName);
        $header = $crawler->filter('header.site-header')->getNode(0);
        self::assertLessThan(
            $this->documentPosition($header),
            $this->documentPosition($notice),
            'The notice must come before the header.',
        );
        self::assertSame(0, $crawler->filter('.announcement')->count());
    }

    /** A page with no CMS announcement keeps one band, holding the store's own line. */
    public function testThereIsStillExactlyOneNoticeWhenNoAnnouncementIsConfigured(): void
    {
        $crawler = $this->home();

        self::assertSame(1, $crawler->filter('.notice .notice-text')->count());
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

    /**
     * The reference page and this storefront, block for block.
     *
     * `tema/index.html` is the design this page was taken from, so it is parsed here rather than
     * transcribed: the test reads the reference's own block sequence out of the reference file and
     * compares it with the sequence the rendered homepage actually produces. A block added, dropped
     * or reordered on either side fails this, which is the whole point — the list in this file
     * cannot drift away from `tema/index.html` without the test noticing.
     */
    public function testTheRenderedHomepageHasTheThemesBlockSequence(): void
    {
        $category = $this->category('Fren', 'fren');
        $brand = $this->brand('Brembo', 'brembo');
        $products = [
            $this->product('SEQ-01', 'Balata', 'seq-balata'),
            $this->product('SEQ-02', 'Disk', 'seq-disk'),
            $this->product('SEQ-03', 'Balyat', 'seq-balyat'),
        ];
        $image = $this->cmsImage();
        $slugs = array_map(static fn (Product $product): string => $product->slug(), $products);

        $post = new BlogPost('Fren bakımı', 'fren-bakimi', 'Fren bakımının adımları.', 'Gövde');
        $this->manager->persist($post);
        $this->manager->flush();
        $this->connection->executeStatement('UPDATE cms_blog_post SET published = 1 WHERE id = ?', [$post->id()]);

        $this->section(HomeSectionType::AnnouncementBar, 'Duyuru', ['text' => 'Kargo duyurusu'], 10);
        $this->section(HomeSectionType::CategoryMenu, 'Kategoriler', ['slugs' => [$category->slug()]], 20);
        $this->section(HomeSectionType::HeroSlider, 'Kampanya', ['slides' => [
            $this->slide(['title' => 'Birinci']),
            $this->slide(['title' => 'İkinci', 'secondaryText' => 'Kategoriler', 'secondaryLink' => '/yeni/kategoriler']),
        ]], 30);
        $this->section(HomeSectionType::ProductCarousel, 'Çok Satanlar', ['slugs' => $slugs], 40);
        $this->section(HomeSectionType::BannerGrid, 'Kampanyalar', ['banners' => [
            ['title' => 'Yaz', 'image' => $image, 'link' => '/yeni/katalog'],
            ['title' => 'Kış', 'image' => $image, 'link' => '/yeni/katalog'],
            ['title' => 'Bahar', 'image' => $image, 'link' => '/yeni/katalog'],
            ['title' => 'Sonbahar', 'image' => $image, 'link' => '/yeni/katalog'],
        ]], 50);
        $this->section(HomeSectionType::ProductCarousel, 'Yeni Ürünler', ['slugs' => $slugs], 60);
        $this->section(HomeSectionType::Features, 'Güvence', ['features' => [
            ['title' => 'Hızlı kargo', 'description' => 'Aynı gün'],
            ['title' => 'Güvenli ödeme', 'description' => 'Kart bilgisi saklanmaz'],
            ['title' => 'Kolay iade', 'description' => '14 gün'],
            ['title' => 'Destek', 'description' => '7/24'],
        ]], 70);
        $this->section(HomeSectionType::SplitBuilder, 'Fren aksesuarları', [
            'label' => 'Yetkili Satıcı',
            'headline' => 'Ücretsiz kargo',
            'description' => 'Stoklarla sınırlıdır.',
            'cta' => 'İncele',
            'link' => '/yeni/katalog',
            'slugs' => $slugs,
        ], 80);
        $this->section(HomeSectionType::BrandStrip, 'Popüler Markalar', ['slugs' => [$brand->slug()]], 90);
        $this->section(HomeSectionType::ProductTabs, 'Ürünler', ['tabs' => [
            ['title' => 'Çok Satanlar', 'slugs' => $slugs],
            ['title' => 'Popüler', 'slugs' => $slugs],
            ['title' => 'İndirimdekiler', 'slugs' => $slugs],
            ['title' => 'Öne Çıkanlar', 'slugs' => $slugs],
        ]], 100);
        $this->section(HomeSectionType::Marquee, 'Avantajlarımız', ['items' => ['Hızlı Gönderim', 'Güvenilir Markalar']], 110);
        $this->section(HomeSectionType::Testimonials, 'Müşterilerimiz', ['quotes' => [
            ['author' => 'Mehmet Y.', 'text' => 'Çok hızlı teslim edildi.'],
        ]], 120);
        $this->section(HomeSectionType::BlogFeed, 'Blog', ['limit' => 3], 130);

        $crawler = $this->home();

        self::assertSame($this->themeBlockSequence(), $this->renderedBlockSequence($crawler));
    }

    /**
     * The measurements, not just the markup.
     *
     * Block order can be right while the page still does not look like the reference: a hero that
     * is 400px tall, a grid with four columns, a closing row split the other way round — all of
     * that would pass every test above and still be a different design. So the storefront
     * stylesheet is read here and compared against `tema/assets/css/style.css`'s own values, and
     * the comparison is made between the two files rather than against a list written in this one.
     *
     * @param string $selector           the selector the storefront gives this measurement
     * @param string $declaration        the declaration the storefront gives it
     * @param string $referenceSelector  the reference's own selector for the same measurement
     * @param string $referenceDeclaration the reference's own declaration for it
     */
    #[DataProvider('themeMetrics')]
    public function testTheStorefrontStylesheetCarriesTheThemesHomepageGeometry(string $selector, string $declaration, string $referenceSelector, string $referenceDeclaration): void
    {
        $ours = $this->declarationsOf(dirname(__DIR__, 3).'/assets/styles/storefront.css');
        $theirs = $this->declarationsOf(dirname(__DIR__, 3).'/tema/assets/css/style.css');

        self::assertArrayHasKey($referenceSelector, $theirs, 'The reference no longer styles '.$referenceSelector.'.');
        self::assertArrayHasKey($referenceDeclaration, $theirs[$referenceSelector], 'The reference no longer gives '.$referenceSelector.' a '.$referenceDeclaration.'.');
        self::assertArrayHasKey($selector, $ours, 'The storefront no longer styles '.$selector.'.');
        self::assertArrayHasKey($declaration, $ours[$selector], 'The storefront no longer gives '.$selector.' a '.$declaration.'.');

        // The reference states its geometry in px at a 16px root; the storefront states the same
        // measurements in rem, so the two are compared as the pixel values they both mean.
        self::assertSame(
            $this->pixelsOf($theirs[$referenceSelector][$referenceDeclaration]),
            $this->pixelsOf($ours[$selector][$declaration]),
            $selector.' { '.$declaration.' } does not match the reference.',
        );
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function themeMetrics(): iterable
    {
        // The reference's own selectors and declarations. Where the storefront scopes a rule to
        // keep it away from the catalogue, its own selector is named; the measurement compared is
        // still the reference's.
        yield 'upper row side columns' => ['.home-top', 'grid-template-columns', '.home-top', 'grid-template-columns'];
        yield 'upper row gap' => ['.home-top', 'gap', '.home-top', 'gap'];
        yield 'promo grid' => ['.promo-grid', 'grid-template-columns', '.promo-grid', 'grid-template-columns'];
        yield 'feature strip' => ['.features', 'grid-template-columns', '.features', 'grid-template-columns'];
        yield 'product grid' => ['.home-section .product-grid', 'grid-template-columns', '.product-grid', 'grid-template-columns'];
        yield 'brand shelf' => ['.brand-strip', 'grid-template-columns', '.brand-strip', 'grid-template-columns'];
        yield 'blog cards' => ['.blog-grid', 'grid-template-columns', '.blog-grid', 'grid-template-columns'];
        yield 'closing row' => ['.testimonial-blog', 'grid-template-columns', '.testimonial-blog', 'grid-template-columns'];
        yield 'split builder' => ['.split-builder', 'grid-template-columns', '.split-builder', 'grid-template-columns'];
        yield 'hero height' => ['.hero', 'height', '.hero', 'height'];
        yield 'category column height' => ['.category-panel', 'height', '.category-panel', 'height'];
        yield 'top sellers height' => ['.top-sellers', 'height', '.top-sellers', 'height'];
        yield 'seller row height' => ['.seller', 'height', '.seller', 'height'];
        // The ticker deliberately uses compact typography instead of the reference's large static band.
        yield 'section card padding' => ['.section-card', 'padding', '.section-card', 'padding'];
        yield 'hero photo left' => ['.hero-photo', 'left', '.hero-photo', 'left'];
        yield 'hero photo width' => ['.hero-photo', 'width', '.hero-photo', 'width'];
        yield 'hero photo height' => ['.hero-photo', 'height', '.hero-photo', 'height'];
        yield 'product card thumbnail' => ['.product-thumb', 'height', '.product-thumb', 'height'];
        yield 'big promo min height' => ['.big-promo', 'min-height', '.big-promo', 'min-height'];
        yield 'big promo padding' => ['.big-promo', 'padding', '.big-promo', 'padding'];
    }

    /**
     * Every `selector { property: value }` pair in a stylesheet.
     *
     * Only a rule that states no condition of its own is kept, and the first declaration of a
     * property is the one recorded: a later duplicate belongs to a breakpoint, and what the desktop
     * looks like is the unconditioned rule. Anything behind `@media` is skipped on both sides, so
     * the two files are read the same way and compared like for like.
     *
     * A comma-separated selector list is recorded one selector at a time, because the storefront
     * scopes some of the reference's rules to its own markup (`minmax(0, 1fr)` instead of `1fr`,
     * a card that only exists on the homepage) and that difference should not hide the measurement.
     *
     * @return array<string, array<string, string>>
     */
    /**
     * The same rules with every `@media` block removed first.
     *
     * {@see self::declarationsOf()} reads the whole sheet, and a flat `selector { … }` pattern
     * cannot tell a rule inside a breakpoint from one outside it: it pairs each rule with the
     * declarations that happen to follow its braces, so a `display: none` written for the tablet
     * breakpoint lands on whatever rule was parsed just before it. That is harmless for a
     * measurement the reference states at the desktop size, and wrong for a question about which
     * declarations a selector carries, so the breakpoints are taken out of the file before it is
     * parsed rather than worked around afterwards.
     *
     * @return array<string, array<string, string>>
     */
    private function desktopDeclarationsOf(string $path): array
    {
        // Comments come off first: they are prose about the rules, they carry braces and at-rule
        // words of their own, and a comment is not a rule the parser should be reading.
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path));
        $css = (string) preg_replace('#@media[^{}]*\{(?:[^{}]*\{[^{}]*\}[^{}]*)*\}#s', '', $css);

        return $this->declarationsOfString($css);
    }

    /** @return array<string, array<string, string>> */
    private function declarationsOf(string $path): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path));

        return $this->declarationsOfString($css);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function declarationsOfString(string $css): array
    {
        $rules = [];
        if (preg_match_all('#([^{}]+)\{([^{}]*)\}#', $css, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $list = trim(preg_replace('/\s+/', ' ', $match[1]) ?? '');
                if ('' === $list || str_contains($list, '@')) {
                    continue;
                }
                foreach (array_filter(array_map('trim', explode(';', $match[2]))) as $declaration) {
                    [$property, $value] = array_pad(explode(':', $declaration, 2), 2, '');
                    foreach (explode(',', $list) as $selector) {
                        $rules[trim($selector)][trim($property)] ??= trim($value);
                    }
                }
            }
        }

        return $rules;
    }

    /**
     * A declaration's value as the measurement it is about.
     *
     * A rem value is converted at the 16px root both files are written against, so a rule can be
     * compared across the two even though they do not spell the same number in the same unit. A
     * `repeat()` is reduced to its column count, because the reference says `repeat(4, 1fr)` and
     * the storefront says `repeat(4, minmax(0, 1fr))` — the same four columns, spelled the way
     * this codebase spells them everywhere else so that long product names cannot blow a track
     * out of the grid. Anything naming no number at all — `1fr`, `auto`, `82%` — comes back as
     * itself with its spacing normalised, which is exactly what the other side has to say.
     */
    private function pixelsOf(string $value): string
    {
        if (preg_match('/^repeat\((\d+)\s*,/', $value, $matches)) {
            return 'repeat('.$matches[1];
        }
        if (!preg_match_all('/(-?[\d.]+)(rem|px)/', $value, $numbers, \PREG_SET_ORDER)) {
            return (string) preg_replace('/\s+/', '', $value);
        }

        return implode(' ', array_map(
            static fn (array $number): string => self::number((float) $number[1] * ('rem' === $number[2] ? 16 : 1)),
            $numbers,
        ));
    }

    /** A pixel value rounded to the theme's own 0.8px granularity, so 0.0625rem reads as 1. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    /**
     * The blocks `tema/index.html` draws, in the order it draws them.
     *
     * @return list<string>
     */
    private function themeBlockSequence(): array
    {
        $reference = file_get_contents(dirname(__DIR__, 3).'/tema/index.html');
        self::assertIsString($reference);
        $document = new \DOMDocument();
        self::assertTrue($document->loadHTML($reference, \LIBXML_NOERROR | \LIBXML_NOWARNING));
        $xpath = new \DOMXPath($document);

        $sequence = [];
        // The notice band is a child of the body; every other block is a child of the page shell.
        foreach (['//body/*', '//body/main/*'] as $query) {
            foreach ($xpath->query($query) ?: [] as $node) {
                foreach ($this->blockClassesOf($node) as $block) {
                    $sequence[] = $block;
                }
            }
        }

        self::assertContains('home-top', $sequence, 'The reference page no longer has the upper band.');
        self::assertContains('split-builder', $sequence, 'The reference page no longer has the split builder.');

        return $sequence;
    }

    /**
     * The same blocks, read out of the rendered homepage: the notice above the header, then the
     * children of the page shell in document order.
     *
     * @return list<string>
     */
    private function renderedBlockSequence(Crawler $crawler): array
    {
        $sequence = [];
        foreach ($crawler->filter('body > .notice') as $node) {
            foreach ($this->blockClassesOf($node) as $block) {
                $sequence[] = $block;
            }
        }
        foreach ($crawler->filter('main#main-content > *') as $node) {
            foreach ($this->blockClassesOf($node) as $block) {
                $sequence[] = $block;
            }
        }

        return $sequence;
    }

    /**
     * The block a top-level element is, judged the way the theme's own markup is written.
     *
     * A block is named by the class that identifies it: the bands and grids have one
     * (`home-top`, `promo-grid`, `features`, `split-builder`, `marquee`, `testimonial-blog`,
     * `home-section`), and a white card is a product block whatever it is called — the theme's own
     * three of them are all `section-card`, and so are ours.
     *
     * @return list<string>
     */
    private function blockClassesOf(\DOMNode $node): array
    {
        if (!$node instanceof \DOMElement) {
            return [];
        }
        $classes = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];

        foreach (['home-top', 'promo-grid', 'features', 'split-builder', 'marquee', 'testimonial-blog', 'notice'] as $block) {
            if (\in_array($block, $classes, true)) {
                return [$block];
            }
        }

        return \in_array('section-card', $classes, true) ? ['section-card'] : [];
    }

    private function documentPosition(\DOMNode $node): int
    {
        $position = 0;
        while ($node instanceof \DOMNode) {
            $position += 2 * max(0, $node->childNodes->length) + 1;
            $node = $node->parentNode;
        }

        return $position;
    }

    private function home(): Crawler
    {
        $crawler = static::getClient()->request('GET', '/yeni/');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * One hero slide in the theme's own shape, so a test only has to state what it is about.
     *
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function slide(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Yeni Ürünler',
            'title' => 'Kampanya',
            'priceLabel' => '',
            'priceValue' => '',
            'primaryText' => '',
            'primaryLink' => '',
            'secondaryText' => '',
            'secondaryLink' => '',
            'image' => $this->cmsImage(),
        ], $overrides);
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
