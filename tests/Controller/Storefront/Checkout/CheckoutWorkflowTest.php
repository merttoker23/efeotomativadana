<?php

declare(strict_types=1);

namespace App\Tests\Controller\Storefront\Checkout;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\Cart;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Entity\Customer\CustomerAddress;
use App\Entity\Customer\CustomerUser;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CheckoutWorkflowTest extends WebTestCase
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

    public function testAnonymousCustomerIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/yeni/odeme');

        self::assertResponseRedirects('/yeni/giris');
    }

    public function testCustomerPlacesLocalOrderAndPostedTotalsAreIgnored(): void
    {
        $customer = $this->customer('web-checkout@example.com');
        $address = $this->address($customer, 'Ev', 'Atatürk Cad. 8');
        $this->cartLine($customer, 12_345, 4, 2);
        $this->client->loginUser($customer, 'main');

        $crawler = $this->client->request('GET', '/yeni/odeme');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Ödeme ve sipariş');
        self::assertSelectorTextContains('.checkout-payment-warning', 'üretim dışı');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);
        $this->client->request('POST', '/yeni/odeme', [
            '_token' => $token,
            'shipping_address' => (string) $address->id(),
            'billing_address' => (string) $address->id(),
            'shipping_option' => 'local_standard',
            'payment_option' => 'local_manual',
            'total' => '1',
            'tax' => '0',
        ]);

        $order = $this->connection->fetchAssociative('SELECT order_number, subtotal_minor_amount, tax_minor_amount, grand_total_minor_amount FROM commerce_customer_order WHERE customer_id = ?', [$customer->id()]);
        self::assertIsArray($order);
        self::assertResponseRedirects('/yeni/siparis/'.$order['order_number'].'/basarili');
        self::assertSame(24_690, (int) $order['subtotal_minor_amount']);
        self::assertSame(4_115, (int) $order['tax_minor_amount']);
        self::assertSame(24_690, (int) $order['grand_total_minor_amount']);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT quantity FROM commerce_product_inventory'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_cart WHERE customer_id = ?', [$customer->id()]));

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Siparişiniz alındı');
        self::assertSelectorTextContains('.order-number', (string) $order['order_number']);
        self::assertSelectorTextContains('.order-payment', 'üretim dışı');
        self::assertSelectorTextContains('.order-line', 'Web Checkout Ürünü');

        $attacker = $this->customer('other@example.com');
        $this->client->loginUser($attacker, 'main');
        $this->client->request('GET', '/yeni/siparis/'.$order['order_number'].'/basarili');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCheckoutPostRequiresCsrfAndKeepsCartOnFailure(): void
    {
        $customer = $this->customer('csrf-checkout@example.com');
        $address = $this->address($customer, 'Ev', 'Güven Cad. 1');
        $this->cartLine($customer, 10_000, 3, 1);
        $this->client->loginUser($customer, 'main');

        $this->client->request('POST', '/yeni/odeme', [
            'shipping_address' => $address->id(),
            'billing_address' => $address->id(),
            'shipping_option' => 'local_standard',
            'payment_option' => 'local_manual',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_customer_order'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_cart WHERE customer_id = ?', [$customer->id()]));
    }

    private function customer(string $email): CustomerUser
    {
        $customer = new CustomerUser($email, 'Efe', 'Yılmaz');
        $customer->setPassword('test-password-hash');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function address(CustomerUser $customer, string $label, string $line): CustomerAddress
    {
        $address = new CustomerAddress($customer);
        $address->update($label, 'Efe Yılmaz', '05000000000', $line, null, 'Seyhan', 'Adana', '01000', true);
        $this->entityManager->persist($address);
        $this->entityManager->flush();

        return $address;
    }

    private function cartLine(CustomerUser $customer, int $minorAmount, int $stock, int $quantity): void
    {
        $product = new Product('WEB-CHECKOUT-SKU', 'Web Checkout Ürünü', 'web-checkout-urunu');
        $product->publish();
        $cart = new Cart($customer);
        $cart->add($product, $quantity);
        foreach ([
            $product,
            new ProductPrice($product, Money::ofMinor($minorAmount, 'TRY'), TaxCategory::of('replacement-part'), TaxRate::fromBasisPoints(2_000)),
            new ProductInventory($product, $stock),
            $cart,
        ] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }
}
