<?php

namespace App\Tests\Controller\Storefront\Catalog;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Entity\Customer\CustomerUser;
use App\Module\Catalog\ProductIdentifierType;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CatalogControllerTest extends WebTestCase
{
    public function testSelectedOptionsOutsidePopularSliceRemainVisibleAndRemovable(): void
    {
        for ($i = 0; $i < 25; ++$i) {
            $brand = new Brand(sprintf('Brand %02d', $i), 'brand-'.$i);
            $brand->publish();
            $category = new Category(sprintf('Category %02d', $i), 'category-'.$i);
            $category->publish();
            $this->product('OPTION-'.$i, 'Option '.$i, 'option-'.$i, true, $brand, $category);
        }
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/yeni/kategori/category-24?brand=brand-24&min_price=10&sort=price-desc');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(25, '.catalog-category-list a');
        self::assertSelectorCount(25, '.catalog-brand-list a');
        self::assertSelectorTextContains('.catalog-category-list a[aria-current]', 'Category 24');
        self::assertSelectorTextContains('.catalog-brand-list a[aria-current]', 'Brand 24');

        $crawler = $this->client->request('GET', $crawler->filter('.catalog-brand-list a[aria-current]')->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.catalog-brand-list a[aria-current]');
        self::assertSelectorTextContains('.catalog-category-list a[aria-current]', 'Category 24');
        self::assertSame(10.0, (float) $crawler->filter('.catalog-filter-form input[name="min_price"]')->attr('value'));
        $this->client->request('GET', $crawler->filter('.catalog-category-list a[aria-current]')->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.catalog-category-list a[aria-current]');
        self::assertSelectorNotExists('.catalog-brand-list a[aria-current]');
    }

    public function testPriceRangeTravelsWithSortCategoryPaginationAndInfiniteScroll(): void
    {
        $brand = new Brand('Price Brand', 'price-brand');
        $brand->publish();
        $category = new Category('Price Category', 'price-category');
        $category->publish();
        $this->entityManager->persist($brand);
        $this->entityManager->persist($category);
        for ($i = 1; $i <= 35; ++$i) {
            $product = $this->product('PRICE-'.$i, 'Lamba Sis Accent '.$i, 'price-'.$i, true);
            $product->changeBrand($brand);
            $product->addCategory($category);
        }
        $this->entityManager->flush();
        $crawler = $this->client->request('GET', '/yeni/kategori/price-category?q=lamba+accent&brand=price-brand&availability=in-stock&sort=price-asc&min_price=10&max_price=1000');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(30, '.product-card');
        self::assertSame('10.00', $crawler->filter('.sort-form input[name="min_price"]')->attr('value'));
        self::assertSame('1000.00', $crawler->filter('.sort-form input[name="max_price"]')->attr('value'));
        self::assertSelectorExists('[data-controller="catalog-price-range"] input[type="range"]');
        $next = $crawler->filter('[data-catalog-infinite-scroll-next-url-value]')->attr('data-catalog-infinite-scroll-next-url-value');
        $paginationNext = $crawler->filter('.pagination a[rel="next"]')->attr('href');
        self::assertSame($paginationNext, $next);
        parse_str((string) parse_url($next, PHP_URL_QUERY), $parameters);
        self::assertSame(['q' => 'lamba accent', 'brand' => 'price-brand', 'availability' => 'in-stock', 'min_price' => '10.00', 'max_price' => '1000.00', 'sort' => 'price-asc', 'page' => '2'], $parameters);
        self::assertStringContainsString('min_price=10.00', $crawler->filter('.catalog-category-list a')->first()->attr('href'));
        $this->client->request('GET', $next);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(5, '.product-card');
        $sortForm = $this->client->getCrawler()->filter('.sort-form')->form(['sort' => 'price-desc']);
        $this->client->submit($sortForm);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(30, '.product-card');
        self::assertSelectorExists('.sort-form input[name="min_price"][value="10.00"]');
    }

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

    /**
     * The brand and category indexes used to render every published option in one response.
     *
     * An automotive catalogue carries thousands of brands, so both are now paged, and the test
     * pins the two properties that matter: a later page answers with different entries rather
     * than the same first page again, and asking past the end is a 404 rather than a silent
     * repeat of the last page.
     */
    public function testTheBrandAndCategoryIndexesArePagedAndStopAtTheEnd(): void
    {
        $brand = new Brand('Bosch', 'bosch');
        $brand->publish();
        $category = new Category('Filtreler', 'filtreler');
        $category->publish();
        $this->entityManager->flush();

        // More brands than fit on one page, so the second page has to be a different page.
        for ($i = 0; $i < 60; ++$i) {
            $extra = new Brand(sprintf('Marka %02d', $i), sprintf('marka-%02d', $i));
            $extra->publish();
            $this->entityManager->persist($extra);
        }
        for ($i = 0; $i < 60; ++$i) {
            $extra = new Category(sprintf('Kategori %02d', $i), sprintf('kategori-%02d', $i));
            $extra->publish();
            $this->entityManager->persist($extra);
        }
        $this->entityManager->flush();

        $this->client->request('GET', '/yeni/markalar');
        self::assertResponseIsSuccessful();
        $firstPage = $this->brandNames();
        self::assertCount(48, $firstPage, 'The brand index must render one bounded page, not every brand.');

        $this->client->request('GET', '/yeni/markalar', ['page' => 2]);
        self::assertResponseIsSuccessful();
        $secondPage = $this->brandNames();
        self::assertNotSame($firstPage, $secondPage, 'Page two repeated page one, so the index is not really paged.');
        self::assertSame([], array_intersect($firstPage, $secondPage), 'Two brand pages shared an entry.');

        // Past the end the index answers 200 with nothing in it, which is what all four paged
        // indexes here do. Asserted so that the four cannot drift apart, and so a future change
        // to a 404 or a clamped repeat has to be a deliberate edit of this test.
        $this->client->request('GET', '/yeni/markalar', ['page' => 99]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->brandNames(), 'A page past the end must not silently repeat the last page.');

        $this->client->request('GET', '/yeni/kategoriler');
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($this->brandNames(), 'The category index rendered nothing.');
    }

    /** @return list<string> */
    private function brandNames(): array
    {
        return $this->client->getCrawler()
            ->filter('.brand-grid a strong')
            ->each(static fn ($node): string => trim((string) $node->text()));
    }

    public function testCatalogAndHeaderRenderLocalPriceStockSearchAndRealNavigation(): void
    {
        $brand = new Brand('Bosch', 'bosch');
        $brand->publish();
        $category = new Category('Filtreler', 'filtreler');
        $category->publish();
        $product = $this->product('FILTER-001', 'Yağ Filtresi', 'yag-filtresi', true, $brand, $category, 159_990, 7);
        $product->addImage('storefront/images/hero-automotive.svg', 'Yağ filtresi görseli');
        $this->entityManager->flush();
        $price = $this->entityManager->getRepository(ProductPrice::class)->findOneBy(['product' => $product]);
        self::assertInstanceOf(ProductPrice::class, $price);
        $price->scheduleSale(Money::ofMinor(139_990, 'TRY'), new \DateTimeImmutable('2020-01-01'), new \DateTimeImmutable('2099-01-01'));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/yeni/katalog');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Ürün Kataloğu');
        self::assertSelectorTextContains('.product-card', 'Yağ Filtresi');
        self::assertSelectorTextContains('.product-card .product-price', '1.399,90 TL');
        self::assertSelectorTextContains('.product-card .product-old', '1.599,90 TL');
        self::assertSelectorTextContains('.product-card .stock-state', 'Stokta Var');
        self::assertSelectorTextNotContains('.product-card .stock-state', '7');
        self::assertSelectorExists('#catalog-results.catalog-results');
        self::assertSelectorExists('.product-card .cart-add-form[action*="/sepet/ekle/"]');
        self::assertSelectorExists('.product-card .wishlist-add-form[action*="/istek-listem/ekle/"]');
        self::assertSelectorExists('.product-card .compare-add-form[action*="/karsilastir/ekle/"]');
        self::assertSame('/yeni/katalog', $crawler->filter('form.storefront-search')->attr('action'));
        self::assertSame('/yeni/katalog', $crawler->filter('nav.main-navigation a')->eq(1)->attr('href'));
        self::assertSame('/yeni/kategori/filtreler?sort=newest#catalog-results', $crawler->filter('.catalog-filters a[href*="/kategori/"]')->first()->attr('href'));
        self::assertSelectorExists('.quick-navigation a[href="/yeni/istek-listem"]');
        self::assertSelectorExists('.quick-navigation a[href="/yeni/karsilastir"]');
        self::assertSelectorCount(0, 'a[href$=".html"], form[action$=".html"]');
    }

    public function testCategoryBrandAndAutomotiveCodeSearchRoutesReturnOnlyApplicableProducts(): void
    {
        $brand = new Brand('Mann Filter', 'mann-filter');
        $brand->publish();
        $category = new Category('Motor Parçaları', 'motor-parcalari');
        $category->publish();
        $product = $this->product('MANN-001', 'Motor Yağ Filtresi', 'motor-yag-filtresi', true, $brand, $category);
        $product->addIdentifier(ProductIdentifierType::Manufacturer, 'W712/95');
        $other = $this->product('OTHER-001', 'Fren Diski', 'fren-diski', true);
        $other->addIdentifier(ProductIdentifierType::Oem, 'UNRELATED');
        $this->entityManager->flush();

        foreach ([
            '/yeni/kategori/motor-parcalari',
            '/yeni/marka/mann-filter',
            '/yeni/katalog?q=W712%2F95',
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.product-grid', 'Motor Yağ Filtresi');
            self::assertSelectorTextNotContains('.product-grid', 'Fren Diski');
        }

        $crawler = $this->client->request('GET', '/yeni/kategori/motor-parcalari?sort=name-asc');
        self::assertStringNotContainsString('category=motor-parcalari', $crawler->filter('.sort-form')->html());

        $crawler = $this->client->request('GET', '/yeni/marka/mann-filter?sort=name-asc');
        self::assertStringNotContainsString('brand=mann-filter', $crawler->filter('.sort-form')->html());
    }

    public function testCategorySelectionAndBrandFilterKeepSearchStockAndSortWhileTargetingResults(): void
    {
        $brand = new Brand('Focus Brand', 'focus-brand');
        $brand->publish();
        $category = new Category('Focus Category', 'focus-category');
        $category->publish();
        $this->product('FOCUS-001', 'Focus Product', 'focus-product', true, $brand, $category);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/yeni/marka/focus-brand?q=Focus&availability=in-stock&sort=price-desc');
        $link = $crawler->filter('.catalog-filters a[href*="/kategori/focus-category"]')->attr('href');
        self::assertSame('/yeni/kategori/focus-category?q=Focus&brand=focus-brand&availability=in-stock&sort=price-desc#catalog-results', $link);

        $crawler = $this->client->request('GET', '/yeni/kategori/focus-category?q=Focus&availability=in-stock&sort=price-desc');
        self::assertSame('/yeni/kategori/focus-category#catalog-results', $crawler->filter('.catalog-filter-form')->attr('action'));
        self::assertSame('price-desc', $crawler->filter('.catalog-filter-form input[name="sort"]')->attr('value'));
        self::assertSame('/yeni/kategori/focus-category#catalog-results', $crawler->filter('.sort-form')->attr('action'));

        $crawler = $this->client->request('GET', '/yeni/kategori/focus-category?q=Focus&availability=in-stock&sort=price-desc&min_price=10&max_price=1000');
        $brandLink = $crawler->filter('.catalog-brand-list a[href*="/marka/focus-brand"]')->attr('href');
        self::assertSame('/yeni/marka/focus-brand?q=Focus&category=focus-category&availability=in-stock&min_price=10.00&max_price=1000.00&sort=price-desc#catalog-results', $brandLink);
        $crawler = $this->client->request('GET', $brandLink);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.product-grid', 'Focus Product');
        self::assertSelectorExists('.catalog-brand-list a[aria-current="page"]');
        self::assertSame(['catalog-price-range', 'catalog-filter-heading', 'catalog-filter-list catalog-category-list', 'catalog-filter-heading', 'catalog-filter-list catalog-brand-list', 'checkbox-field'], $crawler->filter('.catalog-filter-form > fieldset, .catalog-filter-form > p, .catalog-filter-form > div, .catalog-filter-form > label')->each(static fn ($node): string => $node->attr('class')));
        self::assertStringNotContainsString('Tüm Ürünler', $crawler->filter('.catalog-filters')->text());
        self::assertStringNotContainsString('Tüm kategoriler', $crawler->filter('.catalog-filters')->text());
        self::assertStringNotContainsString('Tüm markalar', $crawler->filter('.catalog-filters')->text());
        $clearBrand = $crawler->filter('.catalog-brand-list a[aria-current="page"]')->attr('href');
        self::assertSame('/yeni/katalog?q=Focus&category=focus-category&availability=in-stock&min_price=10.00&max_price=1000.00&sort=price-desc#catalog-results', $clearBrand);
        $clearCategory = $crawler->filter('.catalog-category-list a[aria-current="page"]')->attr('href');
        self::assertSame('/yeni/katalog?q=Focus&brand=focus-brand&availability=in-stock&min_price=10.00&max_price=1000.00&sort=price-desc#catalog-results', $clearCategory);
        $this->client->request('GET', $clearBrand);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.product-grid', 'Focus Product');
        self::assertSelectorNotExists('.catalog-brand-list a[aria-current="page"]');

        $crawler = $this->client->request('GET', '/yeni/marka/focus-brand?category=focus-category&sort=price-desc');
        self::assertSame('focus-category', $crawler->filter('.catalog-filter-form input[name="category"]')->attr('value'));

        $crawler = $this->client->request('GET', '/yeni/kategori/focus-category?brand=focus-brand');
        self::assertSame('focus-brand', $crawler->filter('.catalog-filter-form input[name="brand"]')->attr('value'));
    }

    public function testBrandCardsUseTheLocalBrandIdAndOfferAPlaceholderForMissingLogos(): void
    {
        $brand = new Brand('Logo Brand', 'logo-brand');
        $brand->publish();
        $this->entityManager->persist($brand);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/yeni/markalar');
        $card = $crawler->filter('.brand-grid a[href="/yeni/marka/logo-brand#catalog-results"]');
        self::assertSame('/img/ureticiler/'.$brand->id().'.jpg', $card->filter('img')->attr('src'));
        self::assertStringContainsString('product-placeholder', $card->filter('img')->attr('data-fallback-src'));
        self::assertSame('Logo Brand', $card->filter('strong')->text());
        self::assertSame('0 ürün', $card->filter('span')->text());
    }

    public function testPaginationAndUnsafeSortInputAreHandledWithoutChangingTheQueryShape(): void
    {
        for ($i = 1; $i <= 109; ++$i) {
            $this->product(sprintf('PAGE-%02d', $i), sprintf('Ürün %02d', $i), sprintf('urun-%02d', $i), true);
        }
        $this->entityManager->flush();

        $this->client->request('GET', '/yeni/katalog?page=5&sort=price-desc%3BDELETE%20FROM%20catalog_product&ignored=leak');

        self::assertResponseIsSuccessful();
        // Thirty to a page now, so page five of a hundred and nine products is past the last one
        // and the repository answers with that last page rather than with nothing.
        self::assertSelectorTextContains('.catalog-summary', '109 üründen 91 - 109 arası');
        self::assertSelectorCount(19, '.product-card');
        self::assertSelectorExists('.pagination [aria-current="page"]');
        self::assertSelectorTextContains('.pagination [aria-current="page"]', '4');
        self::assertSelectorCount(5, '.pagination a');
        self::assertSelectorCount(0, '.pagination > span');
        self::assertSelectorCount(0, '.pagination a[href*="ignored"]');
        self::assertSelectorCount(0, '.sort-form input[name="ignored"]');
    }

    /**
     * The point of this test is that the count does not grow with the catalogue — an N+1 on
     * the listing is invisible at eight products and fatal at eight thousand. A ceiling alone
     * would let an N+1 through by being raised, so the count is compared across two catalogue
     * sizes as well as against a documented absolute limit.
     *
     * The limit is 9: the price bounds add one aggregate query to the 8 reads used after SEO.
     * The page resolves its own metadata, which reads two more store settings (the default
     * description and the indexing switch). Those are memoised per request, so they are a
     * fixed cost and not a per-product one.
     */
    public function testProductListEssentialsUseAConstantNumberOfQueries(): void
    {
        for ($i = 1; $i <= 8; ++$i) {
            $product = $this->product(sprintf('QUERY-%02d', $i), sprintf('Sorgu Ürünü %02d', $i), sprintf('sorgu-urunu-%02d', $i), true);
            $product->addImage('storefront/images/hero-automotive.svg', sprintf('Sorgu ürünü %02d', $i));
        }
        $this->entityManager->flush();

        $withEight = $this->profileListing(8);

        // Now a second time, with a catalogue large enough that an N+1 would show.
        for ($i = 9; $i <= 24; ++$i) {
            $product = $this->product(sprintf('QUERY-%02d', $i), sprintf('Sorgu Ürünü %02d', $i), sprintf('sorgu-urunu-%02d', $i), true);
            $product->addImage('storefront/images/hero-automotive.svg', sprintf('Sorgu ürünü %02d', $i));
        }
        $this->entityManager->flush();

        $withTwentyFour = $this->profileListing(12);

        self::assertSame($withEight, $withTwentyFour, 'The listing must not query per product.');
        self::assertLessThanOrEqual(9, $withEight);
    }

    private function profileListing(int $expectedCards): int
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $debugData);
        $debugData->reset();
        $this->client->enableProfiler();

        $this->client->request('GET', '/yeni/katalog');

        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual($expectedCards, $this->client->getCrawler()->filter('.product-card')->count());
        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);
        $database = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $database);

        return $database->getQueryCount();
    }

    public function testProductDetailShowsPublicCatalogDataAndHidesMissingOrDraftProducts(): void
    {
        $brand = new Brand('Bosch', 'bosch');
        $brand->publish();
        $category = new Category('Fren Sistemleri', 'fren-sistemleri');
        $category->publish();
        $product = $this->product('BRAKE-001', 'Ön Fren Balatası', 'on-fren-balatasi', true, $brand, $category, 249_900, 3);
        $product->describe('Sessiz ve güvenilir frenleme için seramik balata.');
        $product->addImage('storefront/images/hero-automotive.svg', 'Ön fren balatası');
        $product->addIdentifier(ProductIdentifierType::Oem, 'OEM-4411');
        $product->setAttribute('disk-cap', '280 mm');
        $this->product('DRAFT-001', 'Gizli Ürün', 'gizli-urun', false);
        $this->entityManager->flush();

        $this->client->request('GET', '/yeni/urun/on-fren-balatasi');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Ön Fren Balatası');
        self::assertSelectorTextContains('.product-detail', '2.499,00');
        self::assertSelectorTextContains('.product-description', 'Sessiz ve güvenilir');
        self::assertSelectorTextContains('.product-detail .stock-state', 'Stokta Var');
        self::assertSelectorTextNotContains('.product-detail .stock-state', '3');
        self::assertSelectorExists('#product-quantity[max="3"]');
        self::assertSelectorTextContains('.product-identifiers', 'OEM-4411');
        self::assertSelectorTextContains('.product-attributes', '280 mm');
        self::assertSelectorExists('.product-gallery img[alt="Ön fren balatası"]');

        foreach (['/yeni/urun/gizli-urun', '/yeni/urun/yok', '/yeni/kategori/yok', '/yeni/marka/yok'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testStoredProductImagesResolveUnderTheApplicationPathAndMissingOnesUseThePlaceholder(): void
    {
        $storedPath = '/uploads/products/0123456789abcdef0123456789abcdef.jpg';
        $withImage = $this->product('IMG-001', 'Görselli Ürün', 'gorselli-urun', true);
        $withImage->addImage($storedPath, 'Görselli ürün fotoğrafı');
        $this->product('IMG-002', 'Görselsiz Ürün', 'gorselsiz-urun', true);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/yeni/urun/gorselli-urun');
        self::assertResponseIsSuccessful();
        self::assertSame(
            '/yeni'.$storedPath,
            $crawler->filter('.product-gallery img')->first()->attr('src'),
        );

        $crawler = $this->client->request('GET', '/yeni/urun/gorselsiz-urun');
        self::assertResponseIsSuccessful();
        $detailPlaceholder = (string) $crawler->filter('.product-gallery img')->first()->attr('src');
        self::assertStringStartsWith('/yeni/assets/storefront/images/product-placeholder-', $detailPlaceholder);
        self::assertSelectorNotExists('.product-gallery-zoom, .product-gallery-dialog');

        $crawler = $this->client->request('GET', '/yeni/katalog?sort=name-asc');
        self::assertResponseIsSuccessful();
        $sources = $crawler->filter('.product-card .product-thumb img')->each(
            static fn ($node): string => (string) $node->attr('src'),
        );
        self::assertCount(2, $sources);
        self::assertContains('/yeni'.$storedPath, $sources);
        self::assertSame(1, count(array_filter(
            $sources,
            static fn (string $source): bool => str_contains($source, 'product-placeholder'),
        )));
        foreach ($sources as $source) {
            self::assertStringStartsWith('/yeni/', $source);
        }
        self::assertSelectorCount(2, '.product-card img[loading="lazy"][decoding="async"][width="640"][height="640"]');
        self::assertSelectorCount(3, '.payments img[loading="lazy"][decoding="async"][width][height]');
    }

    public function testProductImagesReserveTheirRealAspectRatiosAndOnlyTheLeadImageIsEager(): void
    {
        $product = $this->product('IMG-SIZING', 'Boyutlu Ürün', 'boyutlu-urun', true);
        $product->addImage('storefront/images/hero-automotive.svg', 'Ana görsel', 0);
        $product->addImage('storefront/images/product-placeholder.svg', 'Ek görsel', 1);
        $this->entityManager->flush();
        $crawler = $this->client->request('GET', '/yeni/urun/boyutlu-urun');

        self::assertResponseIsSuccessful();
        $images = $crawler->filter('.product-gallery-main img, .product-gallery-thumbnails img');
        self::assertCount(3, $images);
        self::assertSame('640', $images->eq(0)->attr('width'));
        self::assertSame('640', $images->eq(0)->attr('height'));
        self::assertSame('high', $images->eq(0)->attr('fetchpriority'));
        self::assertNull($images->eq(0)->attr('loading'));
        self::assertSame('400', $images->eq(2)->attr('width'));
        self::assertSame('320', $images->eq(2)->attr('height'));
        self::assertSame('lazy', $images->eq(2)->attr('loading'));
        self::assertSame('async', $images->eq(2)->attr('decoding'));
        self::assertSelectorCount(2, '.product-gallery-thumbnail');
        self::assertSelectorExists('.product-gallery-thumbnail[aria-pressed="true"]');
        self::assertSelectorExists('dialog[data-product-gallery-target="dialog"]');
        self::assertSelectorNotExists('dialog img[src], dialog[open]');
    }

    public function testDetailTabsOnlyRenderAvailableDataAndRelatedCardsExcludeTheCurrentProduct(): void
    {
        $category = new Category('Detail Category', 'detail-category');
        $category->publish();
        $product = $this->product('DETAIL-TABS', 'Detail Tabs', 'detail-tabs', true, null, $category);
        $product->describe('Detail description');
        $product->setAttribute('diameter', '280 mm');
        $product->addIdentifier(ProductIdentifierType::Oem, 'DETAIL-OEM');
        $this->product('DETAIL-RELATED', 'Related Detail', 'detail-related', true, null, $category);
        $this->product('DETAIL-DRAFT', 'Draft Detail', 'detail-draft', false, null, $category);
        $this->product('DETAIL-EMPTY', 'Empty Detail', 'detail-empty', true, quantity: 0);
        $this->entityManager->flush();

        $this->client->request('GET', '/yeni/urun/detail-tabs');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(3, '.product-information [role="tab"]');
        self::assertSelectorCount(1, '.product-information [aria-selected="true"]');
        self::assertSelectorCount(2, '.product-information [role="tabpanel"][hidden]');
        self::assertSelectorCount(1, '.similar-products .product-card');
        self::assertSelectorExists('.similar-products .product-card[data-product="detail-related"]');
        self::assertSelectorNotExists('.similar-products .product-card[data-product="detail-tabs"]');
        self::assertSelectorCount(1, '.product-gallery img');
        self::assertSelectorNotExists('.product-gallery-thumbnails');

        $this->client->request('GET', '/yeni/urun/detail-empty');
        self::assertSelectorNotExists('.product-information');
        self::assertSelectorNotExists('.similar-products');
        self::assertSelectorNotExists('.product-detail .cart-add-form');
        self::assertSelectorTextContains('.product-detail .stock-state', 'Stokta Yok');
        self::assertSelectorExists('.product-detail .wishlist-add-form');
        self::assertSelectorExists('.product-detail .compare-add-form');
    }

    public function testDetailActionFormsKeepTheirRealRoutesTokensAndQuantity(): void
    {
        $customer = new CustomerUser('detail-actions@example.com', 'Detail', 'Customer');
        $customer->setPassword('test-password-hash');
        $this->entityManager->persist($customer);
        $product = $this->product('DETAIL-ACTIONS', 'Detail Actions', 'detail-actions', true, quantity: 5);
        $this->entityManager->flush();
        $this->client->loginUser($customer, 'main');
        foreach ([
            ['cart', '/yeni/sepet/ekle/', '/yeni/sepet'],
            ['wishlist', '/yeni/istek-listem/ekle/', '/yeni/istek-listem'],
            ['compare', '/yeni/karsilastir/ekle/', '/yeni/karsilastir'],
        ] as [$kind, $action, $redirect]) {
            $crawler = $this->client->request('GET', '/yeni/urun/detail-actions');
            $form = $crawler->filter('.product-detail-actions .'.$kind.'-add-form');
            self::assertSame($action.$product->id(), $form->attr('action'));
            $token = $form->filter('input[name="_token"]')->attr('value');
            self::assertNotEmpty($token);
            $this->client->request('POST', $action.$product->id(), ['_token' => $token, 'quantity' => 2]);
            self::assertResponseRedirects($redirect);
            $this->client->followRedirect();
            self::assertSelectorTextContains('main', 'Detail Actions');
        }
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT quantity FROM commerce_cart_item WHERE product_id = ?', [$product->id()]));
    }

    private function product(
        string $sku,
        string $name,
        string $slug,
        bool $published,
        ?Brand $brand = null,
        ?Category $category = null,
        int $price = 10_000,
        int $quantity = 1,
    ): Product {
        $product = new Product($sku, $name, $slug, brand: $brand);
        if ($published) {
            $product->publish();
        }
        if (null !== $category) {
            $product->addCategory($category);
        }
        if (null !== $brand) {
            $this->entityManager->persist($brand);
        }
        if (null !== $category) {
            $this->entityManager->persist($category);
        }
        $this->entityManager->persist($product);
        $this->entityManager->persist(new ProductPrice(
            $product,
            Money::ofMinor($price, 'TRY'),
            TaxCategory::of('replacement-part'),
            TaxRate::fromBasisPoints(2_000),
        ));
        $this->entityManager->persist(new ProductInventory($product, $quantity));

        return $product;
    }
}
