<?php

declare(strict_types=1);

namespace App\Tests\Controller\Storefront;

use App\Entity\Catalog\Product;
use App\Entity\Cms\HomeSection;
use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Entity\Commerce\WishlistItem;
use App\Entity\Customer\CustomerUser;
use App\Module\Cms\HomeSectionType;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderState;
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

/**
 * The homepage's product tabs, and the question each one is actually answering.
 *
 * A tab used to be a title and a list of product slugs, so the title was the only thing that said
 * what a tab was and the four tabs of the reference theme could all have shown the same five
 * products. Each tab now names a source, and each source reads the store's own data:
 *
 *  - the best sellers aggregate real quantities on order lines whose order the domain accepts as
 *    sold, so a cancelled order is not a sale;
 *  - the popular ones count real wishlist entries;
 *  - the discounts are the products whose own price row would print a struck-through price, which
 *    is the pricing module's definition and not a second one;
 *  - the featured ones are the slugs an administrator picked, and only that source has a picker.
 *
 * Three of those can be empty on a shop that has no orders, no favourites or no running discount.
 * An empty source keeps its tab and says why in the panel: dropping the tab would hide a question
 * the store asked, and filling it from another source would make the page advertise something it
 * does not mean.
 */
final class ProductTabsSourcesTest extends WebTestCase
{
    private Connection $connection;
    private EntityManagerInterface $manager;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
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

    public function testEachTabShowsItsOwnSourceAndNotTheTitleItWasCalled(): void
    {
        $sold = $this->product('TAB-01', 'Satılan parça', 'satilan-parca');
        $quiet = $this->product('TAB-02', 'Satılmayan parça', 'satilmayan-parca');
        $cancelled = $this->product('TAB-03', 'İptal edilen parça', 'iptal-edilen-parca');
        $favourite = $this->product('TAB-04', 'Favori parça', 'favori-parca');
        $discounted = $this->product('TAB-05', 'İndirimli parça', 'indirimli-parca');
        $featured = $this->product('TAB-06', 'Öne çıkan parça', 'one-cikan-parca');
        $draft = $this->product('TAB-07', 'Taslak parça', 'taslak-parca');
        $draft->unpublish();

        $customer = $this->customer();
        $this->sell($customer, $sold, 4, OrderState::Confirmed);
        $this->sell($customer, $sold, 1, OrderState::Completed);
        $this->sell($customer, $cancelled, 9, OrderState::Cancelled);
        $this->sell($customer, $draft, 7, OrderState::Completed);

        // Two customers saving the same product is what "popular" means: a wishlist entry is one
        // row per customer and product, so the count is counted across customers.
        $this->manager->persist(new WishlistItem($customer, $favourite));
        $this->manager->persist(new WishlistItem($customer, $quiet));
        $this->manager->persist(new WishlistItem($this->customer('second@example.com'), $favourite));
        $this->manager->flush();

        $this->scheduleSale($discounted, new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+7 days'));

        $this->tabs([
            ['title' => 'Çok Satanlar', 'source' => 'best_sellers', 'slugs' => []],
            ['title' => 'Popüler', 'source' => 'popular', 'slugs' => []],
            ['title' => 'İndirimdekiler', 'source' => 'on_sale', 'slugs' => []],
            ['title' => 'Öne Çıkanlar', 'source' => 'featured', 'slugs' => [$featured->slug(), $sold->slug()]],
        ]);

        $crawler = $this->home();
        $panels = $this->panels($crawler);

        self::assertSame(
            [$sold->slug()],
            $this->slugs($panels[0]),
            'Real units sold, from confirmed and completed orders only: the cancelled order and the unpublished product are not sales.',
        );
        self::assertSame(
            [$favourite->slug(), $quiet->slug()],
            $this->slugs($panels[1]),
            'Real wishlist entries, most saved first.',
        );
        self::assertSame(
            [$discounted->slug()],
            $this->slugs($panels[2]),
            'Only a product whose own price row prints a struck-through price belongs in the discounts.',
        );
        self::assertSame(
            [$featured->slug(), $sold->slug()],
            $this->slugs($panels[3]),
            'The manual source is exactly the products that were picked, in the order they were picked.',
        );
    }

    /** An active discount is the pricing module's own condition, and nothing looser than it. */
    public function testTheDiscountTabExcludesAPriceWhoseSaleIsNotRunning(): void
    {
        $notStarted = $this->product('TAB-11', 'Henüz başlamayan', 'henuz-baslamayan');
        $ended = $this->product('TAB-12', 'Bitmiş indirim', 'bitmis-indirim');
        $running = $this->product('TAB-13', 'Devam eden indirim', 'devam-eden-indirim');

        $this->scheduleSale($notStarted, new \DateTimeImmutable('+2 days'), null);
        $this->scheduleSale($ended, new \DateTimeImmutable('-14 days'), new \DateTimeImmutable('-7 days'));
        $this->scheduleSale($running, new \DateTimeImmutable('-2 days'), null);

        $this->tabs([['title' => 'İndirimdekiler', 'source' => 'on_sale', 'slugs' => []]]);

        $panel = $this->panels($this->home())[0];

        self::assertSame([$running->slug()], $this->slugs($panel));
    }

    public function testASourceWithNothingBehindItKeepsItsTabAndSaysSo(): void
    {
        // A tab the administrator filled in whose product has since been unpublished, and a tab
        // whose source the shop simply has no data for yet.
        $unpublished = $this->product('TAB-21', 'Yayından kalkan', 'yayindan-kalkan');
        $unpublished->unpublish();
        $this->manager->flush();

        $this->tabs([
            ['title' => 'Çok Satanlar', 'source' => 'best_sellers', 'slugs' => []],
            ['title' => 'Öne Çıkanlar', 'source' => 'featured', 'slugs' => [$unpublished->slug()]],
        ]);

        $crawler = $this->home();

        self::assertSame(
            2,
            $crawler->filter('.top-products-tabs .tab-btn')->count(),
            'A source with nothing behind it is not dropped: dropping the tab would hide the question the store asked.',
        );

        $panels = $this->panels($crawler);
        self::assertSame([], $this->slugs($panels[0]));
        self::assertSame('Henüz satışı tamamlanmış ürün bulunmuyor.', trim($panels[0]->filter('.product-grid-empty')->text()));
        self::assertSame([], $this->slugs($panels[1]));
        self::assertSame('Bu sekmede seçili ürün bulunmuyor.', trim($panels[1]->filter('.product-grid-empty')->text()));
    }

    /** Two tabs sharing a source must both show it, and a product must not appear twice in one. */
    public function testTwoTabsOnTheSameSourceBothSeeItAndNoProductIsRepeated(): void
    {
        $product = $this->product('TAB-31', 'Satılan', 'satilan');
        $customer = $this->customer();
        $this->sell($customer, $product, 2, OrderState::Confirmed);

        $this->tabs([
            ['title' => 'Birinci', 'source' => 'best_sellers', 'slugs' => []],
            ['title' => 'İkinci', 'source' => 'best_sellers', 'slugs' => []],
        ]);

        $panels = $this->panels($this->home());

        self::assertSame([$product->slug()], $this->slugs($panels[0]));
        self::assertSame([$product->slug()], $this->slugs($panels[1]));
        self::assertSame(\count($this->slugs($panels[0])), \count(array_unique($this->slugs($panels[0]))));
    }

    /** A tab saved before sources existed is a hand-picked list, and still reads as one. */
    public function testATabStoredBeforeSourcesExistedKeepsWorking(): void
    {
        $product = $this->product('TAB-41', 'Elle seçilen', 'elle-secilen');
        $this->tabs([['title' => 'Eski sekme', 'slugs' => [$product->slug()]]]);

        $panel = $this->panels($this->home())[0];

        self::assertSame([$product->slug()], $this->slugs($panel));
    }

    /**
     * Three tabs on one source cost the same as one, and more products cost no more queries.
     *
     * The comparison is the point, not the ceiling: an N+1 here is invisible with eight products
     * and fatal with eight hundred, and a per-tab read would look like the tab count rather than
     * like the number of distinct sources.
     */
    public function testTheTabStripCostsTheSameNoMatterHowManyTabsShareASource(): void
    {
        $this->sellableCatalogue('TAB-5', 4);
        $this->tabs([
            ['title' => 'Birinci', 'source' => 'best_sellers', 'slugs' => []],
            ['title' => 'İkinci', 'source' => 'best_sellers', 'slugs' => []],
        ]);

        $withFourProducts = $this->profileHome();

        $this->sellableCatalogue('TAB-6', 8);
        $this->tabs([
            ['title' => 'Birinci', 'source' => 'best_sellers', 'slugs' => []],
            ['title' => 'İkinci', 'source' => 'best_sellers', 'slugs' => []],
            ['title' => 'Üçüncü', 'source' => 'best_sellers', 'slugs' => []],
        ]);

        self::assertSame(
            $withFourProducts,
            $this->profileHome(),
            'A third tab on the same source and four more products must not add a single query.',
        );
    }

    public function testEveryHomepageProductSectionKeepsUnavailableProductsAndManualOrder(): void
    {
        $products = [];
        $customer = $this->customer('stock-tabs@example.com');
        for ($index = 0; $index < 4; ++$index) {
            $product = $this->product('STOCK-TAB-'.$index, 'Stock tab '.$index, 'stock-tab-'.$index);
            $products[] = $product;
            $this->sell($customer, $product, $index + 1, OrderState::Confirmed);
            for ($saved = 0; $saved <= $index; ++$saved) {
                $this->manager->persist(new WishlistItem($this->customer('stock-save-'.$index.'-'.$saved.'@example.com'), $product));
            }
            $price = $this->manager->getRepository(ProductPrice::class)->findOneBy(['product' => $product]);
            $price->scheduleSale(Money::ofMinor(9_000 - $index * 1_000, 'TRY'), null, null);
        }
        $this->manager->flush();
        // Both zero stock and positive but blocked stock must remain visible in CMS selections.
        $this->connection->update('commerce_product_inventory', ['quantity' => 0], ['product_id' => $products[3]->id()]);
        $this->connection->update('commerce_product_inventory', ['available_for_sale' => 0], ['product_id' => $products[1]->id()]);
        $manual = [$products[3]->slug(), $products[0]->slug(), $products[1]->slug(), $products[2]->slug()];
        $this->tabs([
            ['title' => 'Çok Satanlar', 'source' => 'best_sellers', 'slugs' => []],
            ['title' => 'Popüler', 'source' => 'popular', 'slugs' => []],
            ['title' => 'İndirimdekiler', 'source' => 'on_sale', 'slugs' => []],
            ['title' => 'Öne Çıkanlar', 'source' => 'featured', 'slugs' => $manual],
        ]);
        $carousel = new HomeSection(HomeSectionType::ProductCarousel, 'Stock carousel', ['slugs' => $manual]);
        $carousel->setEnabled(true);
        $this->manager->persist($carousel);
        $grid = new HomeSection(HomeSectionType::ProductCarousel, 'Stock grid', ['slugs' => $manual]);
        $grid->setEnabled(true);
        $this->manager->persist($grid);
        $split = new HomeSection(HomeSectionType::SplitBuilder, 'Stock split', [
            'label' => 'Stock', 'headline' => 'Stock', 'description' => 'Stock',
            'cta' => 'Products', 'link' => '/yeni/katalog', 'slugs' => $manual,
        ]);
        $split->setEnabled(true);
        $this->manager->persist($split);
        $this->manager->flush();

        $crawler = $this->home();
        $panels = $this->panels($crawler);
        for ($index = 0; $index < 3; ++$index) {
            self::assertSame(['stock-tab-2', 'stock-tab-0', 'stock-tab-3', 'stock-tab-1'], $this->slugs($panels[$index]));
        }
        self::assertSame($manual, $this->slugs($panels[3]));
        self::assertSame(array_map(static fn (string $slug): string => '/yeni/urun/'.$slug, $manual), $crawler->filter('.top-sellers a.seller')->extract(['href']));
        foreach (['Stock grid', 'Stock split'] as $title) {
            self::assertSame($manual, $this->slugs($crawler->filter('section[aria-label="'.$title.'"]')));
        }
        self::assertStringContainsString('Stokta Yok', $crawler->filter('main')->text());
        self::assertCount(1, $crawler->filter('section[aria-label="Stock split"] .big-promo'));

        // Losing all stock keeps the configured sections and banner, with purchase controls disabled.
        $this->connection->update('commerce_product_inventory', ['quantity' => 0], ['product_id' => $products[0]->id()]);
        $this->connection->update('commerce_product_inventory', ['available_for_sale' => 0], ['product_id' => $products[2]->id()]);
        $crawler = $this->home();
        self::assertCount(24, $crawler->filter('main .product-card'));
        self::assertCount(4, $crawler->filter('main .seller'));
        self::assertCount(0, $crawler->filter('.product-grid-empty, main .cart-add-form'));
        self::assertCount(3, $crawler->filter('section[aria-label="Stock grid"], section[aria-label="Stock split"], .top-sellers'));
        self::assertCount(1, $crawler->filter('section[aria-label="Stock split"] .big-promo'));
    }

    /** @return array<int, Crawler> */
    private function panels(Crawler $crawler): array
    {
        $panels = $crawler->filter('[role="tabpanel"]');
        $found = [];
        for ($index = 0; $index < $panels->count(); ++$index) {
            $found[] = $panels->eq($index);
        }

        return $found;
    }

    /** @return list<string> */
    private function slugs(Crawler $panel): array
    {
        $slugs = [];
        foreach ($panel->filter('.product-card .product-name') as $node) {
            \assert($node instanceof \DOMElement);
            if (preg_match('#/urun/([^?/]+)$#', (string) $node->getAttribute('href'), $matches)) {
                $slugs[] = $matches[1];
            }
        }

        return $slugs;
    }

    private function profileHome(): int
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $debugData);
        $debugData->reset();
        $this->client->enableProfiler();

        $this->client->request('GET', '/yeni/');

        self::assertResponseIsSuccessful();
        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);
        $database = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $database);

        return $database->getQueryCount();
    }

    private function home(): Crawler
    {
        $crawler = $this->client->request('GET', '/yeni/');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * Published products, each one really sold in a confirmed order, so the best-seller ranking has
     * something to rank.
     */
    private function sellableCatalogue(string $skuPrefix, int $count): void
    {
        $customer = $this->customer('tabs-'.$skuPrefix.'@example.com');
        for ($index = 0; $index < $count; ++$index) {
            $slug = strtolower($skuPrefix).'-'.$index;
            $product = $this->product($skuPrefix.'-'.$index, 'Ürün '.$index, $slug);
            $this->sell($customer, $product, $index + 1, OrderState::Confirmed);
        }
    }

    /**
     * @param list<array<string, mixed>> $tabs
     */
    private function tabs(array $tabs): void
    {
        $section = new HomeSection(HomeSectionType::ProductTabs, 'Ürünler', ['tabs' => $tabs]);
        $section->setEnabled(true);
        $this->manager->persist($section);
        $this->manager->flush();
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
        $this->manager->persist(new ProductInventory($product, 25));
        $this->manager->flush();

        return $product;
    }

    /**
     * A real sale price on the product's own price row, over the window asked for.
     *
     * The window is the whole point: the discounts tab is the pricing module's own condition about
     * a sale price existing, having started and not yet ended, so a test that only ever scheduled a
     * sale that is running would not notice a tab that ignored either end of it.
     */
    private function scheduleSale(Product $product, ?\DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt): void
    {
        $price = $this->manager->getRepository(ProductPrice::class)->findOneBy(['product' => $product]);
        self::assertInstanceOf(ProductPrice::class, $price);
        $price->scheduleSale(Money::ofMinor(7_000, 'TRY'), $startsAt, $endsAt);
        $this->manager->flush();
    }

    private function customer(string $email = 'tabs@example.com'): CustomerUser
    {
        $customer = new CustomerUser($email, 'Taban', 'Müşterisi');
        $this->manager->persist($customer);
        $this->manager->flush();

        return $customer;
    }

    private function sell(CustomerUser $customer, Product $product, int $quantity, OrderState $state): CustomerOrder
    {
        $unit = 123_456;
        $order = new CustomerOrder(
            sprintf('EOA-20260928-%s', strtoupper(bin2hex(random_bytes(6)))),
            $customer,
            Money::ofMinor($unit * $quantity, 'TRY'),
            Money::ofMinor(20_576 * $quantity, 'TRY'),
            Money::ofMinor(0, 'TRY'),
            Money::ofMinor($unit * $quantity, 'TRY'),
            'local_standard',
            'Yerel standart teslimat',
            'gateway_checkout',
            'Kredi kartı',
            new \DateTimeImmutable('2026-09-28 09:00:00'),
        );
        $order->addItem($product, $product->sku(), $product->name(), $quantity, Money::ofMinor($unit, 'TRY'), 2000, Money::ofMinor(102_880 * $quantity, 'TRY'), Money::ofMinor(20_576 * $quantity, 'TRY'), Money::ofMinor($unit * $quantity, 'TRY'));
        $order->addAddress(OrderAddressRole::Shipping, 'Taban Müşterisi', '05320000000', 'Atatürk Caddesi 1', null, 'Çukurova', 'Adana', '01170', 'TR');
        $order->addAddress(OrderAddressRole::Billing, 'Taban Müşterisi', '05320000000', 'Gaziantep Caddesi 9', null, 'Şahinbey', 'Gaziantep', '27100', 'TR');
        $order->sealSnapshots();
        if (OrderState::Placed !== $state) {
            if (OrderState::Cancelled === $state) {
                $order->transitionTo(OrderState::Cancelled);
            } else {
                $order->transitionTo(OrderState::Confirmed);
                if (OrderState::Completed === $state) {
                    $order->transitionTo(OrderState::Completed);
                }
            }
        }
        $this->manager->persist($order);
        $this->manager->flush();

        return $order;
    }
}
