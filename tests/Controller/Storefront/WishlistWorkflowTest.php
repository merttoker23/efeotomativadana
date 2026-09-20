<?php

namespace App\Tests\Controller\Storefront;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Entity\Customer\CustomerUser;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
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

        $crawler = $this->client->request('GET', '/yeni/urun/amortisor');
        self::assertSelectorExists('.wishlist-add-form');
        $this->client->submit($crawler->selectButton('İstek listesine ekle')->form());

        self::assertResponseRedirects('/yeni/istek-listem');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.wishlist-item', 'Amortisör');
        self::assertSame($customer->id(), (int) $this->connection->fetchOne('SELECT customer_id FROM commerce_wishlist_item'));
        self::assertSame($product->id(), (int) $this->connection->fetchOne('SELECT product_id FROM commerce_wishlist_item'));
    }

    public function testCustomerCanRemoveAnOwnedWishlistItem(): void
    {
        $customer = $this->customer('wishlist-remove@example.com');
        $product = $this->product('WISH-REMOVE', 'Fren Diski', 'fren-diski');
        $this->client->loginUser($customer, 'main');
        $crawler = $this->client->request('GET', '/yeni/urun/fren-diski');
        $this->client->submit($crawler->selectButton('İstek listesine ekle')->form());

        $crawler = $this->client->followRedirect();
        self::assertSelectorExists('.wishlist-remove-form');
        $this->client->submit($crawler->selectButton('Listeden kaldır')->form());

        self::assertResponseRedirects('/yeni/istek-listem');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.cart-empty', 'İstek listeniz boş');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_wishlist_item'));
    }

    public function testGuestWishlistActionClearlyRedirectsToLogin(): void
    {
        $this->product('WISH-GUEST', 'Yağ Pompası', 'yag-pompasi');
        $crawler = $this->client->request('GET', '/yeni/urun/yag-pompasi');

        $this->client->submit($crawler->selectButton('İstek listesine ekle')->form());

        self::assertResponseRedirects('/yeni/giris');
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
        $crawler = $this->client->request('GET', '/yeni/urun/triger-seti');
        $this->client->submit($crawler->selectButton('İstek listesine ekle')->form());
        $ownedItemId = (int) $this->connection->fetchOne('SELECT id FROM commerce_wishlist_item WHERE customer_id = ?', [$owner->id()]);

        $this->client->loginUser($attacker, 'main');
        $crawler = $this->client->request('GET', '/yeni/urun/devirdaim');
        $this->client->submit($crawler->selectButton('İstek listesine ekle')->form());
        $crawler = $this->client->followRedirect();
        $token = $crawler->filter('.wishlist-remove-form input[name="_token"]')->attr('value');
        $this->client->request('POST', '/yeni/istek-listem/'.$ownedItemId.'/sil', ['_token' => $token]);

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
