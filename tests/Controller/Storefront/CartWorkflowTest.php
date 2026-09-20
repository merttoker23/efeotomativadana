<?php

namespace App\Tests\Controller\Storefront;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\Cart;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Entity\Customer\CustomerUser;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CartWorkflowTest extends WebTestCase
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
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testGuestCanOpenAnEmptyServerRenderedCart(): void
    {
        $this->client->request('GET', '/yeni/sepet');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Sepetim');
        self::assertSelectorTextContains('.cart-empty', 'Sepetiniz boş');
        self::assertSelectorExists('a.header-action[href="/yeni/sepet"]');
    }

    public function testHeaderCartSummaryUsesAConstantNumberOfQueries(): void
    {
        $customer = $this->customer('cart-summary@example.com', 'VeryStrong!123');
        $cart = new Cart($customer);
        for ($i = 1; $i <= 8; ++$i) {
            $cart->add($this->sellableProduct(
                sprintf('CART-SUMMARY-%02d', $i),
                sprintf('Sepet Özeti Ürünü %02d', $i),
                sprintf('sepet-ozeti-urunu-%02d', $i),
                10_000,
                5,
            ), 1);
        }
        $this->entityManager->persist($cart);
        $this->entityManager->flush();
        $this->client->loginUser($customer, 'main');

        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $debugData);
        $debugData->reset();
        $this->client->enableProfiler();

        $this->client->request('GET', '/yeni/katalog');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.cart-action', '8');
        self::assertSelectorTextContains('.cart-action', '800,00 TRY');
        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);
        $database = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $database);
        self::assertLessThanOrEqual(8, $database->getQueryCount());
    }

    public function testGuestAddsAProductAndPostedPriceIsIgnored(): void
    {
        $product = $this->sellableProduct('CART-001', 'Fren Balatası', 'fren-balatasi', 12_345, 5);
        $crawler = $this->client->request('GET', '/yeni/urun/fren-balatasi');
        self::assertSelectorExists('.cart-add-form input[name="_token"]');
        $token = $crawler->filter('.cart-add-form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/yeni/sepet/ekle/'.$product->id(), [
            '_token' => $token,
            'quantity' => 2,
            'price' => 1,
        ]);

        self::assertResponseRedirects('/yeni/sepet');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_cart'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT quantity FROM commerce_cart_item'));
        $persistedToken = $this->connection->fetchOne('SELECT guest_token FROM commerce_cart');
        self::assertIsString($persistedToken);
        self::assertSame($persistedToken, $this->client->getRequest()->getSession()->get('storefront_guest_cart_token'));
        $this->client->followRedirect();
        self::assertSame($persistedToken, $this->client->getRequest()->getSession()->get('storefront_guest_cart_token'));
        self::assertSelectorTextContains('.cart-line', 'Fren Balatası');
        self::assertSelectorExists('.cart-line input[name="quantity"][value="2"]');
        self::assertSelectorTextContains('.cart-summary', '246,90 TRY');
        self::assertSelectorTextContains('.cart-action', '2');
    }

    public function testCumulativeAddsCannotExceedTheCartQuantityLimit(): void
    {
        $product = $this->sellableProduct('CART-LIMIT', 'Fren Hidroliği', 'fren-hidroligi', 8_000, 150);
        $crawler = $this->client->request('GET', '/yeni/urun/fren-hidroligi');
        self::assertSelectorExists('.cart-add-form input[name="quantity"][max="99"]');
        $this->client->request('POST', '/yeni/sepet/ekle/'.$product->id(), [
            '_token' => $crawler->filter('.cart-add-form input[name="_token"]')->attr('value'),
            'quantity' => 60,
        ]);

        $crawler = $this->client->request('GET', '/yeni/urun/fren-hidroligi');
        $this->client->request('POST', '/yeni/sepet/ekle/'.$product->id(), [
            '_token' => $crawler->filter('.cart-add-form input[name="_token"]')->attr('value'),
            'quantity' => 60,
        ]);

        self::assertResponseRedirects('/yeni/sepet');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.storefront-flash-error', '99');
        self::assertSame(60, (int) $this->connection->fetchOne('SELECT quantity FROM commerce_cart_item'));
    }

    public function testGuestCanUpdateAOwnedCartLineWithinCurrentStock(): void
    {
        $product = $this->sellableProduct('CART-UPDATE', 'Yağ Filtresi', 'yag-filtresi', 10_000, 5);
        $crawler = $this->client->request('GET', '/yeni/urun/yag-filtresi');
        $this->client->request('POST', '/yeni/sepet/ekle/'.$product->id(), [
            '_token' => $crawler->filter('.cart-add-form input[name="_token"]')->attr('value'),
            'quantity' => 1,
        ]);

        $crawler = $this->client->followRedirect();
        self::assertSelectorExists('.cart-line form.cart-update-form');
        $this->client->submit($crawler->selectButton('Güncelle')->form([
            'quantity' => 4,
        ]));

        self::assertResponseRedirects('/yeni/sepet');
        $this->client->followRedirect();
        self::assertSelectorExists('.cart-line input[name="quantity"][value="4"]');
        self::assertSelectorTextContains('.cart-summary', '400,00 TRY');
        self::assertSame(4, (int) $this->connection->fetchOne('SELECT quantity FROM commerce_cart_item'));
    }

    public function testCartKeepsAnUnavailablePriceLineVisibleWithoutCalculatingATotal(): void
    {
        $product = $this->sellableProduct('CART-NO-PRICE', 'Yakıt Filtresi', 'yakit-filtresi', 13_500, 4);
        $crawler = $this->client->request('GET', '/yeni/urun/yakit-filtresi');
        $this->client->request('POST', '/yeni/sepet/ekle/'.$product->id(), [
            '_token' => $crawler->filter('.cart-add-form input[name="_token"]')->attr('value'),
            'quantity' => 1,
        ]);
        $this->connection->executeStatement('DELETE FROM commerce_product_price WHERE product_id = ?', [$product->id()]);
        $this->entityManager->clear();

        $this->client->request('GET', '/yeni/sepet');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.cart-line');
        self::assertSelectorTextContains('.cart-line', 'Yakıt Filtresi');
        self::assertSelectorTextContains('.cart-line-error', 'fiyat');
        self::assertSelectorTextContains('.cart-summary', 'Hesaplanamıyor');
        self::assertSelectorTextContains('.cart-action', '1');
    }

    public function testCartDoesNotPresentAPartialTotalWhenAnyLineIsUnavailable(): void
    {
        $available = $this->sellableProduct('CART-AVAILABLE', 'Şanzıman Yağı', 'sanziman-yagi', 22_500, 4);
        $unavailable = $this->sellableProduct('CART-UNAVAILABLE', 'Direksiyon Yağı', 'direksiyon-yagi', 17_500, 4);
        foreach ([$available, $unavailable] as $product) {
            $crawler = $this->client->request('GET', '/yeni/urun/'.$product->slug());
            $this->client->request('POST', '/yeni/sepet/ekle/'.$product->id(), [
                '_token' => $crawler->filter('.cart-add-form input[name="_token"]')->attr('value'),
                'quantity' => 1,
            ]);
        }
        $this->connection->executeStatement('DELETE FROM commerce_product_price WHERE product_id = ?', [$unavailable->id()]);
        $this->entityManager->clear();

        $this->client->request('GET', '/yeni/sepet');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '.cart-line');
        self::assertSelectorTextContains('.cart-summary', 'Hesaplanamıyor');
        self::assertSelectorCount(0, '.cart-action small');
    }

    public function testGuestCanRemoveAnOwnedCartLine(): void
    {
        $product = $this->sellableProduct('CART-REMOVE', 'Hava Filtresi', 'hava-filtresi', 20_000, 2);
        $crawler = $this->client->request('GET', '/yeni/urun/hava-filtresi');
        $this->client->request('POST', '/yeni/sepet/ekle/'.$product->id(), [
            '_token' => $crawler->filter('.cart-add-form input[name="_token"]')->attr('value'),
            'quantity' => 1,
        ]);

        $crawler = $this->client->followRedirect();
        self::assertSelectorExists('.cart-line form.cart-remove-form');
        $this->client->submit($crawler->selectButton('Kaldır')->form());

        self::assertResponseRedirects('/yeni/sepet');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.cart-empty', 'Sepetiniz boş');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_cart_item'));
    }

    public function testLoginMergesMatchingGuestLineIntoCustomerCartWithoutExceedingStock(): void
    {
        $product = $this->sellableProduct('CART-MERGE', 'Polen Filtresi', 'polen-filtresi', 15_000, 3);
        $customer = $this->customer('merge@example.com', 'VeryStrong!123');
        $customerCart = new Cart($customer);
        $customerCart->add($product, 2);
        $this->entityManager->persist($customerCart);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/yeni/urun/polen-filtresi');
        $this->client->request('POST', '/yeni/sepet/ekle/'.$product->id(), [
            '_token' => $crawler->filter('.cart-add-form input[name="_token"]')->attr('value'),
            'quantity' => 2,
        ]);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_cart'));

        $crawler = $this->client->request('GET', '/yeni/giris');
        $this->client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => 'merge@example.com',
            '_password' => 'VeryStrong!123',
        ]));

        self::assertResponseRedirects('/yeni/hesabim');
        $this->client->request('GET', '/yeni/sepet');
        self::assertSelectorCount(1, '.cart-line');
        self::assertSelectorExists('.cart-line input[name="quantity"][value="3"]');
        self::assertSelectorTextContains('.storefront-flash-warning', 'stok');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_cart'));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT quantity FROM commerce_cart_item'));
        self::assertNull($this->client->getRequest()->getSession()->get('storefront_guest_cart_token'));
    }

    public function testLoginClampsAnExistingCustomerLineToCurrentStockDuringMerge(): void
    {
        $product = $this->sellableProduct('CART-MERGE-STALE', 'Motor Takozu', 'motor-takozu', 35_000, 8);
        $customer = $this->customer('stale-merge@example.com', 'VeryStrong!123');
        $customerCart = new Cart($customer);
        $customerCart->add($product, 5);
        $this->entityManager->persist($customerCart);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/yeni/urun/motor-takozu');
        $this->client->request('POST', '/yeni/sepet/ekle/'.$product->id(), [
            '_token' => $crawler->filter('.cart-add-form input[name="_token"]')->attr('value'),
            'quantity' => 1,
        ]);
        $inventory = $this->entityManager->getRepository(ProductInventory::class)->findOneBy(['product' => $product]);
        self::assertInstanceOf(ProductInventory::class, $inventory);
        $inventory->replace(3, true);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/yeni/giris');
        $this->client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => 'stale-merge@example.com',
            '_password' => 'VeryStrong!123',
        ]));

        self::assertResponseRedirects('/yeni/hesabim');
        $this->client->request('GET', '/yeni/sepet');
        self::assertSelectorExists('.cart-line input[name="quantity"][value="3"]');
        self::assertSelectorTextContains('.storefront-flash-warning', 'stok');
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT quantity FROM commerce_cart_item'));
    }

    public function testExcessQuantityDoesNotCreateACartLine(): void
    {
        $product = $this->sellableProduct('CART-STOCK', 'Rot Başı', 'rot-basi', 9_000, 3);
        $crawler = $this->client->request('GET', '/yeni/urun/rot-basi');
        $this->client->request('POST', '/yeni/sepet/ekle/'.$product->id(), [
            '_token' => $crawler->filter('.cart-add-form input[name="_token"]')->attr('value'),
            'quantity' => 4,
        ]);

        self::assertResponseRedirects('/yeni/sepet');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.storefront-flash-error', 'En fazla 3');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_cart_item'));
    }

    public function testCartMutationRequiresCsrfProtection(): void
    {
        $product = $this->sellableProduct('CART-CSRF', 'Z Rot', 'z-rot', 11_000, 3);

        $this->client->request('POST', '/yeni/sepet/ekle/'.$product->id(), [
            'quantity' => 1,
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_cart_item'));
    }

    public function testGuestCannotUpdateAnotherSessionCart(): void
    {
        $product = $this->sellableProduct('CART-OWNER', 'Aks Kafası', 'aks-kafasi', 18_000, 5);
        $crawler = $this->client->request('GET', '/yeni/urun/aks-kafasi');
        $this->client->request('POST', '/yeni/sepet/ekle/'.$product->id(), [
            '_token' => $crawler->filter('.cart-add-form input[name="_token"]')->attr('value'),
            'quantity' => 2,
        ]);
        $itemId = (int) $this->connection->fetchOne('SELECT id FROM commerce_cart_item');

        $this->client->getCookieJar()->clear();
        $attackerProduct = $this->sellableProduct('CART-ATTACKER', 'Debriyaj Seti', 'debriyaj-seti', 40_000, 5);
        $crawler = $this->client->request('GET', '/yeni/urun/debriyaj-seti');
        $this->client->request('POST', '/yeni/sepet/ekle/'.$attackerProduct->id(), [
            '_token' => $crawler->filter('.cart-add-form input[name="_token"]')->attr('value'),
            'quantity' => 1,
        ]);
        $crawler = $this->client->followRedirect();
        $token = $crawler->filter('.cart-update-form input[name="_token"]')->attr('value');
        $this->client->request('POST', '/yeni/sepet/'.$itemId.'/guncelle', [
            '_token' => $token,
            'quantity' => 5,
        ]);

        self::assertResponseRedirects('/yeni/sepet');
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT quantity FROM commerce_cart_item WHERE id = ?', [$itemId]));
    }

    private function sellableProduct(string $sku, string $name, string $slug, int $price, int $quantity): Product
    {
        $product = new Product($sku, $name, $slug);
        $product->publish();
        $this->entityManager->persist($product);
        $this->entityManager->persist(new ProductPrice(
            $product,
            Money::ofMinor($price, 'TRY'),
            TaxCategory::of('replacement-part'),
            TaxRate::fromBasisPoints(2_000),
        ));
        $this->entityManager->persist(new ProductInventory($product, $quantity));
        $this->entityManager->flush();

        return $product;
    }

    private function customer(string $email, string $password): CustomerUser
    {
        $customer = new CustomerUser($email, 'Efe', 'Yılmaz');
        $customer->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, $password));
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }
}
