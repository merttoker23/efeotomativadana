<?php

namespace App\Tests\Controller\Storefront;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Entity\Commerce\WishlistItem;
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

final class WishlistWorkflowTest extends WebTestCase
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

    public function testCustomerAddsAProductToAnOwnedWishlist(): void
    {
        $customer = $this->customer('wishlist@example.com');
        $product = $this->product('WISH-001', 'Amortisör', 'amortisor');
        $this->client->loginUser($customer, 'main');

        $crawler = $this->client->request('GET', '/urun/amortisor');
        self::assertSelectorExists('.wishlist-add-form');
        $this->client->submit($crawler->selectButton('İstek listesine ekle')->form());

        self::assertResponseRedirects('/istek-listem');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.wishlist-item', 'Amortisör');
        self::assertSame($customer->id(), (int) $this->connection->fetchOne('SELECT customer_id FROM commerce_wishlist_item'));
        self::assertSame($product->id(), (int) $this->connection->fetchOne('SELECT product_id FROM commerce_wishlist_item'));
    }

    public function testEverySavedProductCanBeReachedThroughBoundedOwnedPages(): void
    {
        $customer = $this->customer('wishlist-pages@example.com');
        $stranger = $this->customer('wishlist-stranger@example.com');
        for ($index = 1; $index <= 53; ++$index) {
            $product = $this->product('WISH-PAGE-'.$index, 'Favori '.$index, 'favori-'.$index);
            $this->entityManager->persist(new WishlistItem($customer, $product));
        }
        $privateProduct = $this->product('WISH-PRIVATE', 'Başkasının favorisi', 'baskasinin-favorisi');
        $this->entityManager->persist(new WishlistItem($stranger, $privateProduct));
        $this->entityManager->flush();
        $this->client->loginUser($customer, 'main');
        $this->client->request('GET', '/istek-listem');

        $names = [];
        $queryCounts = [];
        foreach ([1 => 24, 2 => 24, 3 => 5] as $page => $expectedCount) {
            $debugData = self::getContainer()->get('doctrine.debug_data_holder');
            self::assertInstanceOf(BacktraceDebugDataHolder::class, $debugData);
            $debugData->reset();
            $this->entityManager->clear();
            $this->client->enableProfiler();
            $crawler = $this->client->request('GET', '/istek-listem', ['page' => $page]);
            self::assertResponseIsSuccessful();
            self::assertCount($expectedCount, $crawler->filter('.wishlist-item'));
            self::assertSelectorTextContains('.wishlist-summary', '53 ürün');
            self::assertSelectorTextContains('.pagination [aria-current="page"]', (string) $page);
            self::assertSelectorTextNotContains('main', 'Başkasının favorisi');
            if ($page < 3) {
                self::assertSame('/istek-listem?page='.($page + 1), $crawler->filter('.pagination a[rel="next"]')->attr('href'));
            }
            $names = array_merge($names, $crawler->filter('.wishlist-item strong')->each(static fn ($node): string => $node->text()));
            $profile = $this->client->getProfile();
            self::assertNotFalse($profile);
            $database = $profile->getCollector('db');
            self::assertInstanceOf(DoctrineDataCollector::class, $database);
            $queryCounts[] = $database->getQueryCount();
        }
        self::assertCount(53, array_unique($names), 'Every saved product must appear once across pages.');
        self::assertSame($queryCounts[0], $queryCounts[2], 'A page with 24 saved products must cost the same number of queries as one with 5.');

        $this->client->request('GET', '/istek-listem?page=99');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(0, '.wishlist-item');
        self::assertSelectorTextNotContains('main', 'İstek listeniz boş.');
        self::assertSelectorExists('a[href="/istek-listem?page=1"]');
    }

    public function testCustomerCanRemoveAnOwnedWishlistItem(): void
    {
        $customer = $this->customer('wishlist-remove@example.com');
        $product = $this->product('WISH-REMOVE', 'Fren Diski', 'fren-diski');
        $this->client->loginUser($customer, 'main');
        $crawler = $this->client->request('GET', '/urun/fren-diski');
        $this->client->submit($crawler->selectButton('İstek listesine ekle')->form());

        $crawler = $this->client->followRedirect();
        self::assertSelectorExists('.wishlist-remove-form');
        $this->client->submit($crawler->selectButton('Listeden kaldır')->form());

        self::assertResponseRedirects('/istek-listem');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.cart-empty', 'İstek listeniz boş');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_wishlist_item'));
    }

    public function testGuestWishlistActionClearlyRedirectsToLogin(): void
    {
        $this->product('WISH-GUEST', 'Yağ Pompası', 'yag-pompasi');
        $crawler = $this->client->request('GET', '/urun/yag-pompasi');

        $this->client->submit($crawler->selectButton('İstek listesine ekle')->form());

        self::assertResponseRedirects('/giris');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.storefront-flash-warning', 'giriş yapın');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_wishlist_item'));
    }

    public function testCustomerCannotRemoveAnotherCustomersWishlistItem(): void
    {
        $owner = $this->customer('wishlist-owner@example.com');
        $attacker = $this->customer('wishlist-attacker@example.com');
        $ownedProduct = $this->product('WISH-OWNER', 'Triger Seti', 'triger-seti');
        $attackerProduct = $this->product('WISH-ATTACKER', 'Devirdaim', 'devirdaim');

        $this->client->loginUser($owner, 'main');
        $crawler = $this->client->request('GET', '/urun/triger-seti');
        $this->client->submit($crawler->selectButton('İstek listesine ekle')->form());
        $ownedItemId = (int) $this->connection->fetchOne('SELECT id FROM commerce_wishlist_item WHERE customer_id = ?', [$owner->id()]);

        $this->client->loginUser($attacker, 'main');
        $crawler = $this->client->request('GET', '/urun/devirdaim');
        $this->client->submit($crawler->selectButton('İstek listesine ekle')->form());
        $crawler = $this->client->followRedirect();
        $token = $crawler->filter('.wishlist-remove-form input[name="_token"]')->attr('value');
        $this->client->request('POST', '/istek-listem/'.$ownedItemId.'/sil', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_wishlist_item WHERE id = ?', [$ownedItemId]));
        self::assertSame($ownedProduct->id(), (int) $this->connection->fetchOne('SELECT product_id FROM commerce_wishlist_item WHERE id = ?', [$ownedItemId]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_wishlist_item'));
        self::assertNotSame($ownedProduct->id(), $attackerProduct->id());
    }

    private function customer(string $email): CustomerUser
    {
        $customer = new CustomerUser($email, 'Efe', 'Yılmaz');
        $customer->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, 'VeryStrong!123'));
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function product(string $sku, string $name, string $slug): Product
    {
        $product = new Product($sku, $name, $slug);
        $product->publish();
        $this->entityManager->persist($product);
        $this->entityManager->persist(new ProductPrice(
            $product,
            Money::ofMinor(25_000, 'TRY'),
            TaxCategory::of('replacement-part'),
            TaxRate::fromBasisPoints(2_000),
        ));
        $this->entityManager->persist(new ProductInventory($product, 4));
        $this->entityManager->flush();

        return $product;
    }
}
