<?php

namespace App\Tests\Controller\Storefront\Catalog;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
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
        self::assertSelectorTextContains('.product-card .product-price', '1.399,90 TRY');
        self::assertSelectorTextContains('.product-card .product-old', '1.599,90 TRY');
        self::assertSelectorTextContains('.product-card .stock-state', 'Stokta (7)');
        self::assertSame('/yeni/katalog', $crawler->filter('form.storefront-search')->attr('action'));
        self::assertSame('/yeni/katalog', $crawler->filter('nav.main-navigation a')->eq(1)->attr('href'));
        self::assertSame('/yeni/kategori/filtreler', $crawler->filter('.catalog-filters a[href*="/kategori/"]')->first()->attr('href'));
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

    public function testPaginationAndUnsafeSortInputAreHandledWithoutChangingTheQueryShape(): void
    {
        for ($i = 1; $i <= 109; ++$i) {
            $this->product(sprintf('PAGE-%02d', $i), sprintf('Ürün %02d', $i), sprintf('urun-%02d', $i), true);
        }
        $this->entityManager->flush();

        $this->client->request('GET', '/yeni/katalog?page=5&sort=price-desc%3BDELETE%20FROM%20catalog_product&ignored=leak');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.catalog-summary', '109 üründen 49 - 60 arası');
        self::assertSelectorCount(12, '.product-card');
        self::assertSelectorExists('.pagination [aria-current="page"]');
        self::assertSelectorTextContains('.pagination [aria-current="page"]', '5');
        self::assertSelectorCount(9, '.pagination a');
        self::assertSelectorCount(2, '.pagination > span');
        self::assertSelectorCount(0, '.pagination a[href*="ignored"]');
        self::assertSelectorCount(0, '.sort-form input[name="ignored"]');
    }

    public function testProductListEssentialsUseAConstantNumberOfQueries(): void
    {
        for ($i = 1; $i <= 8; ++$i) {
            $product = $this->product(sprintf('QUERY-%02d', $i), sprintf('Sorgu Ürünü %02d', $i), sprintf('sorgu-urunu-%02d', $i), true);
            $product->addImage('storefront/images/hero-automotive.svg', sprintf('Sorgu ürünü %02d', $i));
        }
        $this->entityManager->flush();
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $debugData);
        $debugData->reset();
        $this->client->enableProfiler();

        $this->client->request('GET', '/yeni/katalog');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(8, '.product-card');
        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);
        $database = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $database);
        self::assertLessThanOrEqual(6, $database->getQueryCount());
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
        self::assertSelectorTextContains('.product-identifiers', 'OEM-4411');
        self::assertSelectorTextContains('.product-attributes', '280 mm');
        self::assertSelectorExists('.product-gallery img[alt="Ön fren balatası"]');

        foreach (['/yeni/urun/gizli-urun', '/yeni/urun/yok', '/yeni/kategori/yok', '/yeni/marka/yok'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404);
        }
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
