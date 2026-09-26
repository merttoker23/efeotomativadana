<?php

declare(strict_types=1);

namespace App\Tests\Controller\Storefront\Payment;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\Cart;
use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Payment;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Entity\Customer\CustomerAddress;
use App\Entity\Customer\CustomerUser;
use App\Module\Payment\FakePaymentGateway;
use App\Module\Payment\Gateway\GatewayInitiationOutcome;
use App\Module\Order\OrderState;
use App\Module\Payment\PaymentState;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Module\Settings\StoreConfiguration;
use App\Repository\Commerce\PaymentRepository;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StorefrontPaymentFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $manager = $container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->entityManager = $manager;
        $gateway = $container->get(FakePaymentGateway::class);
        self::assertInstanceOf(FakePaymentGateway::class, $gateway);
        $this->gateway = $gateway;
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testCheckoutCreatesTheOrderBeforeAnyGatewayCallAndRedirectsToTheProvider(): void
    {
        $customer = $this->customer('flow@example.com');
        $address = $this->address($customer);
        $this->cartLine($customer);
        $this->selectFakeProvider();
        $this->gateway->queueInitiation(GatewayInitiationOutcome::redirect('https://pay.test/hosted/checkout', 'FAKE-FLOW'));
        $this->login($customer);

        $this->client->request('POST', '/yeni/odeme', [
            '_token' => $this->csrf('checkout_place'),
            'shipping_address' => $address->id(),
            'billing_address' => $address->id(),
            'shipping_option' => 'local_standard',
            'payment_option' => 'gateway_checkout',
        ]);

        self::assertSame('https://pay.test/hosted/checkout', $this->client->getResponse()->headers->get('Location'), sprintf('status=%d body=%s', $this->client->getResponse()->getStatusCode(), mb_substr(strip_tags((string) $this->client->getResponse()->getContent()), 0, 400)));

        $order = $this->onlyOrder();
        self::assertSame(OrderState::Placed, $order->state());
        $payment = $this->paymentFor($order);
        self::assertSame(PaymentState::RequiresAction, $payment->state());
        self::assertSame('FAKE-FLOW', $payment->latestAttempt()?->providerReference());
    }

    public function testAnUnreachableProviderLeavesTheOrderPlacedAndOffersARetry(): void
    {
        $customer = $this->customer('gateway-down@example.com');
        $address = $this->address($customer);
        $this->cartLine($customer);
        // No queued outcome: the fake refuses to invent a success.
        $this->selectFakeProvider();
        $this->login($customer);

        $this->client->request('POST', '/yeni/odeme', [
            '_token' => $this->csrf('checkout_place'),
            'shipping_address' => $address->id(),
            'billing_address' => $address->id(),
            'shipping_option' => 'local_standard',
            'payment_option' => 'gateway_checkout',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect(), 'A gateway failure must not lose the order.');

        $order = $this->onlyOrder();
        self::assertNotNull($order->id());
        $this->client->followRedirect();
        self::assertStringContainsString($order->orderNumber(), (string) $this->client->getResponse()->getContent());
    }

    public function testAnUnreachableProviderStillLeavesAWayToPayLater(): void
    {
        $customer = $this->customer('retry-later@example.com');
        $address = $this->address($customer);
        $this->cartLine($customer);
        $this->selectFakeProvider();
        // No queued outcome: the gateway call fails outright.
        $this->login($customer);

        $this->client->request('POST', '/yeni/odeme', [
            '_token' => $this->csrf('checkout_place'),
            'shipping_address' => $address->id(),
            'billing_address' => $address->id(),
            'shipping_option' => 'local_standard',
            'payment_option' => 'gateway_checkout',
        ]);
        $order = $this->onlyOrder();

        // The order survives a dead gateway, so the customer must still be able to start the
        // payment later. Telling them to retry on a page with no way to retry is a dead end.
        $this->client->request('GET', sprintf('/yeni/odeme/%s', $order->orderNumber()));
        self::assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        self::assertGreaterThan(0, $crawler->filter(sprintf('form[action$="/yeni/odeme/%s/yeniden-dene"]', $order->orderNumber()))->count(), 'A payment with no record yet must still offer a way to start it.');

        $this->gateway->queueInitiation(GatewayInitiationOutcome::redirect('https://pay.test/hosted/later', 'FAKE-LATER'));
        $this->client->submit($crawler->filter(sprintf('form[action$="/yeni/odeme/%s/yeniden-dene"] button', $order->orderNumber()))->form());

        self::assertSame('https://pay.test/hosted/later', $this->client->getResponse()->headers->get('Location'));
    }

    public function testALocalManualOrderSkipsTheGatewayEntirely(): void
    {
        $customer = $this->customer('manual@example.com');
        $address = $this->address($customer);
        $this->cartLine($customer);
        $this->login($customer);

        $this->client->request('POST', '/yeni/odeme', [
            '_token' => $this->csrf('checkout_place'),
            'shipping_address' => $address->id(),
            'billing_address' => $address->id(),
            'shipping_option' => 'local_standard',
            'payment_option' => 'local_manual',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        $this->client->followRedirect();
        self::assertStringContainsString('Siparişiniz alındı', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->paymentFor($this->onlyOrder()));
    }

    public function testTheSuccessPageDoesNotClaimSuccessWhileThePaymentIsOpen(): void
    {
        $customer = $this->customer('pending@example.com');
        $address = $this->address($customer);
        $this->cartLine($customer);
        $this->selectFakeProvider();
        $this->gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback('FAKE-PENDING'));
        $this->login($customer);

        $this->client->request('POST', '/yeni/odeme', [
            '_token' => $this->csrf('checkout_place'),
            'shipping_address' => $address->id(),
            'billing_address' => $address->id(),
            'shipping_option' => 'local_standard',
            'payment_option' => 'gateway_checkout',
        ]);
        $order = $this->onlyOrder();
        $this->client->request('GET', sprintf('/yeni/siparis/%s/basarili', $order->orderNumber()));

        self::assertTrue($this->client->getResponse()->isRedirect(sprintf('/yeni/odeme/%s', $order->orderNumber())));
    }

    public function testAVerifiedCallbackConfirmsTheOrderAndShowsTheSuccessPage(): void
    {
        $customer = $this->customer('callback@example.com');
        $address = $this->address($customer);
        $this->cartLine($customer);
        $this->selectFakeProvider();
        $this->gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback('FAKE-CB'));
        $this->login($customer);
        $this->client->request('POST', '/yeni/odeme', [
            '_token' => $this->csrf('checkout_place'),
            'shipping_address' => $address->id(),
            'billing_address' => $address->id(),
            'shipping_option' => 'local_standard',
            'payment_option' => 'gateway_checkout',
        ]);
        $order = $this->onlyOrder();
        $token = $this->tokenFor($order);

        $this->postCallback($token, 'FAKE-CB', 'succeeded', 30_000, 'valid');

        self::assertTrue($this->client->getResponse()->isRedirect(sprintf('/yeni/odeme/%s', $order->orderNumber())));
        $this->client->followRedirect();
        self::assertStringContainsString('Ödemeniz alındı', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', sprintf('/yeni/siparis/%s/basarili', $order->orderNumber()));
        self::assertResponseIsSuccessful();
        self::assertSame('confirmed', $this->reload($order)->state()->value);
    }

    public function testAReplayedCallbackStillLeavesTheOrderConfirmedExactlyOnce(): void
    {
        $customer = $this->customer('replay@example.com');
        $address = $this->address($customer);
        $this->cartLine($customer);
        $this->selectFakeProvider();
        $this->gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback('FAKE-RP'));
        $this->login($customer);
        $this->client->request('POST', '/yeni/odeme', [
            '_token' => $this->csrf('checkout_place'),
            'shipping_address' => $address->id(),
            'billing_address' => $address->id(),
            'shipping_option' => 'local_standard',
            'payment_option' => 'gateway_checkout',
        ]);
        $order = $this->onlyOrder();
        $token = $this->tokenFor($order);

        for ($replay = 0; $replay < 3; ++$replay) {
            $this->postCallback($token, 'FAKE-RP', 'succeeded', 30_000, 'valid');
        }

        self::assertSame('confirmed', $this->reload($order)->state()->value);
        self::assertSame(1, $this->orderStatusChangeCount($order));
        self::assertSame(30_000, $this->paymentFor($this->reload($order))->capturedAmount()->minorAmount());
    }

    public function testAForgedCallbackIsRejectedAndTheOrderStaysPlaced(): void
    {
        $customer = $this->customer('forged@example.com');
        $address = $this->address($customer);
        $this->cartLine($customer);
        $this->selectFakeProvider();
        $this->gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback('FAKE-FG'));
        $this->login($customer);
        $this->client->request('POST', '/yeni/odeme', [
            '_token' => $this->csrf('checkout_place'),
            'shipping_address' => $address->id(),
            'billing_address' => $address->id(),
            'shipping_option' => 'local_standard',
            'payment_option' => 'gateway_checkout',
        ]);
        $order = $this->onlyOrder();

        $this->postCallback($this->tokenFor($order), 'FAKE-FG', 'succeeded', 30_000, 'forged');

        $this->client->followRedirect();
        self::assertStringContainsString('Ödeme doğrulanamadı', (string) $this->client->getResponse()->getContent());
        self::assertSame('placed', $this->reload($order)->state()->value);
        self::assertSame(0, $this->paymentFor($this->reload($order))->capturedAmount()->minorAmount());
    }

    public function testTheCancelRouteRejectsAGetAndRequiresACsrfToken(): void
    {
        $customer = $this->customer('cancel-route@example.com');
        $order = $this->order($customer);
        $this->selectFakeProvider();
        $this->gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback('FAKE-CANCEL-ROUTE'));
        self::getContainer()->get(\App\Module\Payment\PaymentInitiationService::class)->start($order, 'FAKE');
        $token = $this->tokenFor($order);
        $this->client->restart();

        // A cancel URL is handed to the provider and travels through the address bar, browser
        // history and proxy logs. It must not be able to kill a payment with a bare GET.
        $this->client->request('GET', sprintf('/yeni/odeme/iptal/%s', $token));
        self::assertResponseStatusCodeSame(405);

        $this->client->request('POST', sprintf('/yeni/odeme/iptal/%s', $token), [
            'payment_cancel' => ['_token' => 'not-a-real-token'],
        ]);
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403]);

        self::assertNotSame(PaymentState::Cancelled, $this->paymentFor($this->reload($order))->state());
    }

    public function testTheCustomerCancelsTheirOwnOpenPaymentWithAValidToken(): void
    {
        $customer = $this->customer('cancel-own@example.com');
        $order = $this->order($customer);
        $this->selectFakeProvider();
        $this->gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback('FAKE-CANCEL-OWN'));
        $orderNumber = $order->orderNumber();
        self::getContainer()->get(\App\Module\Payment\PaymentInitiationService::class)->start($order, 'FAKE');
        $token = $this->tokenFor($order);
        $this->login($customer);
        $csrf = $this->csrfFromPage('/yeni/odeme/'.$orderNumber, 'input[name="payment_cancel[_token]"]');

        $this->client->request('POST', sprintf('/yeni/odeme/iptal/%s', $token), [
            'payment_cancel' => ['_token' => $csrf],
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode(), sprintf('Location=%s body=%s', $this->client->getResponse()->headers->get('Location'), mb_substr(strip_tags((string) $this->client->getResponse()->getContent()), 0, 200)));
        $reloaded = $this->reload($order);
        self::assertSame(PaymentState::Cancelled, $this->paymentFor($reloaded)->state());
        self::assertSame(OrderState::Placed, $reloaded->state(), 'Abandoning a payment must not cancel the order behind the customer\'s back.');
    }

    public function testAnAnonymousVisitorCannotReachAPaymentPage(): void
    {
        $this->client->request('GET', '/yeni/odeme/EOA-20260925-ABCDEF123456');

        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403]);
    }

    public function testAProviderWebhookWithoutASessionCanStillSettleAPayment(): void
    {
        $customer = $this->customer('webhook@example.com');
        $address = $this->address($customer);
        $this->cartLine($customer);
        $this->selectFakeProvider();
        $this->gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback('FAKE-HOOK'));
        $this->login($customer);
        $this->client->request('POST', '/yeni/odeme', [
            '_token' => $this->csrf('checkout_place'),
            'shipping_address' => $address->id(),
            'billing_address' => $address->id(),
            'shipping_option' => 'local_standard',
            'payment_option' => 'gateway_checkout',
        ]);
        $order = $this->onlyOrder();
        $token = $this->tokenFor($order);

        // A server-to-server webhook arrives with no cookie and no session at all. If the
        // callback route demanded a customer login, a charged order would stay unconfirmed
        // forever and staff would have no in-app way to reconcile it.
        $this->client->restart();
        $this->postCallback($token, 'FAKE-HOOK', 'succeeded', 30_000, 'valid');

        self::assertResponseStatusCodeSame(302);
        self::assertSame('confirmed', $this->reload($order)->state()->value);
        self::assertSame(30_000, $this->paymentFor($this->reload($order))->capturedAmount()->minorAmount());
    }

    public function testACustomerCannotSeeAnotherCustomersPaymentPage(): void
    {
        $owner = $this->customer('owner@example.com');
        $order = $this->order($owner);
        $intruder = $this->customer('intruder@example.com');
        $this->login($intruder);

        $this->client->request('GET', sprintf('/yeni/odeme/%s', $order->orderNumber()));

        // A foreign order is indistinguishable from a nonexistent one: revealing that the
        // number exists would leak order volume to an unauthenticated reader.
        self::assertResponseStatusCodeSame(404);
    }

    public function testRetryRequiresACsrfToken(): void
    {
        $customer = $this->customer('csrf@example.com');
        $order = $this->order($customer);
        $this->login($customer);

        $this->client->request('POST', sprintf('/yeni/odeme/%s/yeniden-dene', $order->orderNumber()), ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testThePaymentPageOffersRetryOnlyForARetryableState(): void
    {
        $customer = $this->customer('retry-page@example.com');
        $order = $this->order($customer);
        $this->login($customer);
        $this->gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback('FAKE-PAGE'));
        $payment = self::getContainer()->get(\App\Module\Payment\PaymentInitiationService::class)->start($order, 'FAKE');
        self::assertFalse($payment->requiresRedirect());

        $this->client->request('GET', sprintf('/yeni/odeme/%s', $order->orderNumber()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('yeniden dene', (string) $this->client->getResponse()->getContent());
    }

    public function testThePaymentPageHidesRetryForACapturedPayment(): void
    {
        $customer = $this->customer('captured-page@example.com');
        $order = $this->order($customer);
        $this->login($customer);
        $this->gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback('FAKE-CAP'));
        $initiation = self::getContainer()->get(\App\Module\Payment\PaymentInitiationService::class);
        $initiation->start($order, 'FAKE');
        $handler = self::getContainer()->get(\App\Module\Payment\PaymentCallbackHandler::class);
        $handler->handle($this->tokenFor($order), new \App\Module\Payment\Gateway\IncomingPaymentCallback(
            http_build_query(['ref' => 'FAKE-CAP', 'outcome' => 'succeeded', 'amount' => '30000', 'currency' => 'TRY']),
            ['X-Fake-Signature' => 'valid'],
            [],
        ));

        $this->client->request('GET', sprintf('/yeni/odeme/%s', $order->orderNumber()));

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('yeniden dene', (string) $this->client->getResponse()->getContent());
    }

    /** Opens a session and renders the checkout page so its real CSRF token is used. */
    /**
     * Posts a provider callback the way a gateway does: a form-encoded body plus a
     * signature header. The body is what the gateway signs, so it must stay byte-identical.
     */
    private function postCallback(string $token, string $reference, string $outcome, int $amount, string $signature): void
    {
        $this->client->request(
            'POST',
            sprintf('/yeni/odeme/sonuc/%s', $token),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'HTTP_X-Fake-Signature' => $signature,
            ],
            http_build_query(['ref' => $reference, 'outcome' => $outcome, 'amount' => (string) $amount, 'currency' => 'TRY']),
        );
    }

    /** Opens a session and renders the checkout page so its real CSRF token is used. */
    private function csrf(string $intention): string
    {
        return $this->csrfFromPage('/yeni/odeme', 'input[name="_token"]');
    }

    /**
     * Reads a CSRF token off a rendered page. A token built by hand would not prove the page
     * actually embeds one the server then accepts.
     */
    private function csrfFromPage(string $path, string $selector): string
    {
        $this->client->request('GET', $path);
        $field = $this->client->getCrawler()->filter($selector)->first();
        self::assertGreaterThan(0, $field->count(), sprintf('No CSRF field "%s" was rendered on %s.', $selector, $path));

        return (string) $field->attr('value');
    }

    private function login(CustomerUser $customer): void
    {
        $this->client->loginUser($customer);
    }

    private function onlyOrder(): CustomerOrder
    {
        $orders = $this->entityManager->getRepository(CustomerOrder::class)->findBy([], ['id' => 'DESC'], 1);

        return $orders[0];
    }

    private function reload(CustomerOrder $order): CustomerOrder
    {
        $this->entityManager->clear();

        return $this->entityManager->find(CustomerOrder::class, $order->id());
    }

    private function orderStatusChangeCount(CustomerOrder $order): int
    {
        return count($this->entityManager->getRepository(\App\Entity\Commerce\OrderStatusChange::class)->findBy(['order' => $order->id()]));
    }

    private function paymentFor(CustomerOrder $order): ?Payment
    {
        $repository = self::getContainer()->get('doctrine')->getRepository(Payment::class);
        self::assertInstanceOf(PaymentRepository::class, $repository);

        return $repository->findOneForOrder($order);
    }

    private function tokenFor(CustomerOrder $order): string
    {
        $payment = $this->paymentFor($order);
        self::assertInstanceOf(Payment::class, $payment);
        $attempt = $payment->latestAttempt();
        self::assertNotNull($attempt);

        return $attempt->returnToken();
    }

    /**
     * Selects the fake gateway the way an administrator would, through the store setting.
     * Checkout resolves the provider from configuration, never from a hard-coded key.
     */
    private function selectFakeProvider(): void
    {
        $configuration = self::getContainer()->get(StoreConfiguration::class);
        self::assertInstanceOf(StoreConfiguration::class, $configuration);
        $settings = $configuration->current();
        $settings->paymentProvider = 'fake';
        $configuration->save($settings);
    }

    private function customer(string $email): CustomerUser
    {
        $customer = new CustomerUser($email, 'Efe', 'Yılmaz');
        $customer->setPassword('test-password-hash');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function address(CustomerUser $customer): CustomerAddress
    {
        $address = new CustomerAddress($customer);
        $address->update('Ev', 'Efe Yılmaz', '05000000000', 'Atatürk Cad. 1', null, 'Seyhan', 'Adana', '01000', false);
        $this->entityManager->persist($address);
        $this->entityManager->flush();

        return $address;
    }

    private function order(CustomerUser $customer): CustomerOrder
    {
        $zero = Money::ofMinor(0, 'TRY');
        $gross = Money::ofMinor(30_000, 'TRY');
        $order = new CustomerOrder(
            'EOA-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(6))),
            $customer,
            $gross,
            $zero,
            $zero,
            $gross,
            'local_standard',
            'Yerel standart teslimat',
            'gateway_checkout',
            'Kredi kartı ile ödeme',
            new \DateTimeImmutable(),
        );
        $order->addItem(null, 'FLOW-SKU', 'Ödeme ürünü', 1, $gross, 0, $gross, $zero, $gross);
        $order->addAddress(\App\Module\Order\OrderAddressRole::Shipping, 'Efe Yılmaz', '05000000000', 'Atatürk Cad. 1', null, 'Seyhan', 'Adana', null, 'TR');
        $order->addAddress(\App\Module\Order\OrderAddressRole::Billing, 'Efe Yılmaz', '05000000000', 'Atatürk Cad. 1', null, 'Seyhan', 'Adana', null, 'TR');
        $order->sealSnapshots();
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    private function cartLine(CustomerUser $customer): void
    {
        $product = new Product('FLOW-SKU-' . bin2hex(random_bytes(6)), 'Ödeme ürünü', 'odeme-urunu-' . bin2hex(random_bytes(6)));
        $product->publish();
        $price = new ProductPrice($product, Money::ofMinor(30_000, 'TRY'), TaxCategory::of('replacement-part'), TaxRate::fromBasisPoints(0));
        $inventory = new ProductInventory($product, 5);
        $cart = new Cart($customer);
        $cart->add($product, 1);
        foreach ([$product, $price, $inventory, $cart] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }
}
