<?php

declare(strict_types=1);

namespace App\Tests\Controller\Storefront\Checkout;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\Cart;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Entity\Customer\CustomerAddress;
use App\Entity\Customer\AdminUser;
use App\Entity\Customer\CustomerUser;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use App\Tests\ResetsRateLimits;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CheckoutWorkflowTest extends WebTestCase
{
    use ResetsRateLimits;
    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->resetRateLimits();
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
        $this->client->request('GET', '/odeme');

        self::assertResponseRedirects('/giris');
    }

    public function testCustomerPlacesLocalOrderAndPostedTotalsAreIgnored(): void
    {
        $customer = $this->customer('web-checkout@example.com');
        $address = $this->address($customer, 'Ev', 'Atatürk Cad. 8');
        $this->cartLine($customer, 12_345, 4, 2);
        $this->client->loginUser($customer, 'main');

        $crawler = $this->client->request('GET', '/odeme');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Ödeme ve sipariş');
        self::assertSelectorTextContains('.checkout-payment-warning', 'üretim dışı');
        self::assertSelectorNotExists('input[name="payment_option"][value="gateway_checkout"]');
        self::assertSelectorTextContains('.checkout-summary', 'Ara Toplam');
        self::assertSelectorTextContains('.checkout-summary', 'Kargo');
        self::assertSelectorTextContains('.checkout-summary-total', 'Genel Toplam');
        self::assertSelectorExists('textarea[name="order_note"][maxlength="1000"]');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);
        $this->client->request('POST', '/odeme', [
            '_token' => $token,
            'shipping_address' => (string) $address->id(),
            'billing_address' => (string) $address->id(),
            'shipping_option' => 'local_standard',
            'payment_option' => 'local_manual',
            'order_note' => "  Öğleden sonra teslim edin.\nA & B  ",
            'total' => '1',
            'tax' => '0',
            'shipping_cost' => '0',
        ]);

        $order = $this->connection->fetchAssociative('SELECT order_number, subtotal_minor_amount, tax_minor_amount, grand_total_minor_amount FROM commerce_customer_order WHERE customer_id = ?', [$customer->id()]);
        self::assertIsArray($order);
        self::assertResponseRedirects('/siparis/'.$order['order_number'].'/basarili');
        self::assertSame(24_690, (int) $order['subtotal_minor_amount']);
        self::assertSame(4_115, (int) $order['tax_minor_amount']);
        self::assertSame(49_690, (int) $order['grand_total_minor_amount']);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT quantity FROM commerce_product_inventory'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_cart WHERE customer_id = ?', [$customer->id()]));
        self::assertSame("Öğleden sonra teslim edin.\nA & B", $this->connection->fetchOne('SELECT order_note FROM commerce_customer_order WHERE customer_id = ?', [$customer->id()]));

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Siparişiniz alındı');
        self::assertSelectorTextContains('.order-number', (string) $order['order_number']);
        self::assertSelectorTextContains('.order-payment', 'üretim dışı');
        self::assertSelectorTextContains('.order-line', 'Web Checkout Ürünü');

        $this->client->request('GET', '/hesabim/siparisler/'.$order['order_number']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="order-note"]', 'Öğleden sonra teslim edin.');
        self::assertStringContainsString('A &amp; B', $this->client->getResponse()->getContent());

        $administrator = new AdminUser('note-admin@example.com');
        $this->entityManager->persist($administrator);
        $this->entityManager->flush();
        $this->client->loginUser($administrator, 'admin');
        $this->client->request('GET', '/admin/orders/'.$order['order_number']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="order-note"]', 'Öğleden sonra teslim edin.');
        self::assertStringContainsString('A &amp; B', $this->client->getResponse()->getContent());

        // Even imported legacy data bypassing domain validation must remain escaped.
        $legacyNote = '<script data-legacy-order-note>alert(1)</script>';
        $this->connection->update('commerce_customer_order', ['order_note' => $legacyNote], ['order_number' => $order['order_number']]);
        $this->entityManager->clear();
        $this->client->request('GET', '/admin/orders/'.$order['order_number']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="order-note"]', $legacyNote);
        self::assertSelectorNotExists('script[data-legacy-order-note]');
        $this->client->loginUser($customer, 'main');
        $this->client->request('GET', '/hesabim/siparisler/'.$order['order_number']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="order-note"]', $legacyNote);
        self::assertSelectorNotExists('script[data-legacy-order-note]');

        $attacker = $this->customer('other@example.com');
        $this->client->loginUser($attacker, 'main');
        $this->client->request('GET', '/siparis/'.$order['order_number'].'/basarili');
        self::assertResponseStatusCodeSame(404);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOrderNotes')]
    public function testInvalidOrderNoteLeavesCartAndStockUntouched(string $note): void
    {
        $customer = $this->customer('invalid-note@example.com');
        $address = $this->address($customer, 'Ev', 'Not Cad. 1');
        $this->cartLine($customer, 10_000, 3, 1);
        $this->client->loginUser($customer, 'main');
        $crawler = $this->client->request('GET', '/odeme');
        $this->client->request('POST', '/odeme', [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'shipping_address' => $address->id(),
            'billing_address' => $address->id(),
            'shipping_option' => 'local_standard',
            'payment_option' => 'local_manual',
            'order_note' => $note,
        ]);
        self::assertResponseRedirects('/odeme');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_customer_order WHERE customer_id = ?', [$customer->id()]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_cart WHERE customer_id = ?', [$customer->id()]));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT quantity FROM commerce_product_inventory'));
        $this->client->followRedirect();
        self::assertSelectorExists('.storefront-flash-error');
        self::assertSelectorNotExists('script[data-invalid-note]');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOrderNotes(): iterable
    {
        yield 'HTML script rejected' => ['<script data-invalid-note>alert(1)</script>'];
        yield 'maximum exceeded' => [str_repeat('ğ', 1001)];
    }

    public function testCheckoutPostRequiresCsrfAndKeepsCartOnFailure(): void
    {
        $customer = $this->customer('csrf-checkout@example.com');
        $address = $this->address($customer, 'Ev', 'Güven Cad. 1');
        $this->cartLine($customer, 10_000, 3, 1);
        $this->client->loginUser($customer, 'main');

        $this->client->request('POST', '/odeme', [
            'shipping_address' => $address->id(),
            'billing_address' => $address->id(),
            'shipping_option' => 'local_standard',
            'payment_option' => 'local_manual',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_customer_order'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_cart WHERE customer_id = ?', [$customer->id()]));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('shippingSummaries')]
    public function testCartCheckoutAndOrderShareShippingPolicy(int $subtotal, bool $custom, string $shipping, string $total, int $shippingMinor, int $totalMinor): void
    {
        $customer = $this->customer('summary-checkout@example.com');
        $address = $this->address($customer, 'Ev', 'Özet Cad. 1');
        $this->cartLine($customer, $subtotal, 1, 1);
        if ($custom) {
            $configuration = self::getContainer()->get(\App\Module\Settings\StoreConfiguration::class);
            $settings = $configuration->current();
            $settings->shippingFee = 32_550;
            $settings->freeShippingThreshold = 200_000;
            $configuration->save($settings);
        }
        $this->client->loginUser($customer, 'main');
        $crawler = $this->client->request('GET', '/sepet');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.cart-summary-subtotal', 'Ara Toplam');
        self::assertSelectorTextContains('.cart-summary-shipping', $shipping);
        self::assertSelectorTextContains('.cart-summary-total', $total);
        self::assertStringNotContainsString('TRY', $crawler->filter('main')->text());
        $crawler = $this->client->request('GET', '/odeme');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($shipping, $crawler->filter('.checkout-summary-line')->last()->text());
        self::assertSelectorTextContains('.checkout-summary-total', $total);
        $this->client->request('POST', '/odeme', [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'shipping_address' => $address->id(),
            'billing_address' => $address->id(),
            'shipping_option' => 'local_standard',
            'payment_option' => 'local_manual',
            'shipping_cost' => '0',
            'total' => '1',
        ]);
        self::assertResponseRedirects();
        $order = $this->connection->fetchAssociative('SELECT subtotal_minor_amount, shipping_minor_amount, grand_total_minor_amount FROM commerce_customer_order WHERE customer_id = ?', [$customer->id()]);
        self::assertIsArray($order);
        self::assertSame($subtotal, (int) $order['subtotal_minor_amount']);
        self::assertSame($shippingMinor, (int) $order['shipping_minor_amount']);
        self::assertSame($totalMinor, (int) $order['grand_total_minor_amount']);
        self::assertNull($this->connection->fetchOne('SELECT order_note FROM commerce_customer_order WHERE customer_id = ?', [$customer->id()]));
        $orderNumber = $this->connection->fetchOne('SELECT order_number FROM commerce_customer_order WHERE customer_id = ?', [$customer->id()]);
        $this->client->request('GET', '/hesabim/siparisler/'.$orderNumber);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="order-note"]');
    }

    /** @return iterable<string, array{int, bool, string, string, int, int}> */
    public static function shippingSummaries(): iterable
    {
        yield 'paid below threshold' => [149_999, false, '250,00 TL', '1.749,99 TL', 25_000, 174_999];
        yield 'free at threshold' => [150_000, false, 'Ücretsiz', '1.500,00 TL', 0, 150_000];
        yield 'free above threshold' => [150_001, false, 'Ücretsiz', '1.500,01 TL', 0, 150_001];
        yield 'new settings' => [150_000, true, '325,50 TL', '1.825,50 TL', 32_550, 182_550];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('freeShippingMessages')]
    public function testFreeShippingMessageUsesConfiguredThresholdWithoutChangingTotals(int $subtotal, int $threshold, ?string $message): void
    {
        $customer = $this->customer('free-shipping@example.com');
        $this->address($customer, 'Ev', 'Özet Cad. 1');
        $this->cartLine($customer, $subtotal, 1, 1);
        $configuration = self::getContainer()->get(\App\Module\Settings\StoreConfiguration::class);
        $settings = $configuration->current();
        $settings->freeShippingThreshold = $threshold;
        $configuration->save($settings);
        $this->client->loginUser($customer, 'main');

        $cost = self::getContainer()->get(\App\Module\Checkout\LocalStandardShippingOption::class)->cost(Money::ofMinor($subtotal, 'TRY'));
        $formatter = self::getContainer()->get(\App\Twig\StorefrontMoneyExtension::class);
        foreach (['/sepet' => 'cart', '/odeme' => 'checkout'] as $url => $page) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('kazandınız', $crawler->filter('main')->text());
            self::assertStringNotContainsString('TRY', $crawler->filter('main')->text());
            if (null === $message) {
                self::assertSelectorNotExists('[data-testid="free-shipping-message"]');
            } else {
                self::assertSelectorTextContains('[data-testid="free-shipping-message"]', $message);
                self::assertSelectorExists('.free-shipping-notice + .'.$page.'-'.('cart' === $page ? 'layout' : 'grid'));
                self::assertSelectorTextContains('[data-testid="free-shipping-message"] strong', $formatter->format(Money::ofMinor($threshold - $subtotal, 'TRY')));
                $progress = $crawler->filter('.free-shipping-notice progress');
                self::assertSame('100', $progress->attr('max'));
                self::assertSame((string) (int) floor($subtotal / $threshold * 100), $progress->attr('value'));
            }
            self::assertSelectorTextContains('.'.$page.'-summary-total', $formatter->format(Money::ofMinor($subtotal, 'TRY')->add($cost)));
        }
    }

    /** @return iterable<string, array{int, int, ?string}> */
    public static function freeShippingMessages(): iterable
    {
        yield '300 TL remaining' => [120_000, 150_000, 'Ücretsiz kargo için 300,00 TL daha ürün ekleyin.'];
        yield '817.03 TL remaining' => [68_297, 150_000, 'Ücretsiz kargo için 817,03 TL daha ürün ekleyin.'];
        yield 'one kurus remaining' => [149_999, 150_000, 'Ücretsiz kargo için 0,01 TL daha ürün ekleyin.'];
        yield 'at threshold' => [150_000, 150_000, null];
        yield 'above threshold' => [180_000, 150_000, null];
        yield 'custom admin threshold' => [120_000, 200_000, 'Ücretsiz kargo için 800,00 TL daha ürün ekleyin.'];
        yield 'zero threshold' => [120_000, 0, null];
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
