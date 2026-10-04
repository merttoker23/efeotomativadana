<?php

declare(strict_types=1);

namespace App\Tests\Controller\Storefront;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Payment;
use App\Entity\Commerce\Shipment;
use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderState;
use App\Module\Payment\FakePaymentGateway;
use App\Module\Payment\Gateway\GatewayInitiationOutcome;
use App\Module\Payment\Gateway\GatewayRefundOutcome;
use App\Module\Payment\Gateway\IncomingPaymentCallback;
use App\Module\Payment\PaymentCallbackHandler;
use App\Module\Payment\PaymentInitiationService;
use App\Module\Payment\SanitizedFailure;
use App\Module\Shipping\ShipmentState;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;

final class CustomerOrderCancellationTest extends WebTestCase
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

    public function testTheOwnerCanSubmitTheOrderCancellationFormAndSeeItsResult(): void
    {
        $order = $this->order();
        $this->client->loginUser($order->customer(), 'main');
        $crawler = $this->client->request('GET', $this->detailUrl($order));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="order-cancel"]');
        $form = $crawler->filter('[data-testid="order-cancel"]')->form();
        self::assertSame('POST', $form->getMethod());
        self::assertStringEndsWith($this->cancelUrl($order), $form->getUri());

        $this->client->submit($form);

        self::assertResponseRedirects($this->detailUrl($order));
        self::assertSame('cancelled', $this->persistedState($order));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.storefront-flash-success', 'Siparişiniz iptal edildi');
        self::assertSelectorNotExists('[data-testid="order-cancel"]');
    }

    public function testAnAnonymousCancellationIsRedirectedToLogin(): void
    {
        $order = $this->order();

        $this->client->request('POST', $this->cancelUrl($order), ['_token' => 'invalid']);

        self::assertResponseRedirects('/yeni/giris');
        self::assertSame('placed', $this->persistedState($order));
    }

    public function testAForeignOrderAndAnUnknownNumberBothReturn404(): void
    {
        $foreign = $this->order();
        $own = $this->order();
        $this->client->loginUser($own->customer(), 'main');
        $token = $this->tokenFor($own);

        $this->client->request('POST', $this->cancelUrl($foreign), ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('placed', $this->persistedState($foreign));
        $this->client->request('POST', '/yeni/hesabim/siparisler/EOA-20261005-000000000000/iptal', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('placed', $this->persistedState($own));
    }

    public function testGETCannotCancelAnOrder(): void
    {
        $order = $this->order();
        $this->client->loginUser($order->customer(), 'main');

        $this->client->request('GET', $this->cancelUrl($order));

        self::assertResponseStatusCodeSame(405);
        self::assertSame('placed', $this->persistedState($order));
    }

    #[DataProvider('invalidTokens')]
    public function testMissingOrInvalidCsrfDoesNotCancelAnOrder(?string $token): void
    {
        $order = $this->order();
        $this->client->loginUser($order->customer(), 'main');

        $this->client->request('POST', $this->cancelUrl($order), null === $token ? [] : ['_token' => $token]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('placed', $this->persistedState($order));
        self::assertSame(0, $this->cancellationCount($order));
    }

    /** @return iterable<string, array{?string}> */
    public static function invalidTokens(): iterable
    {
        yield 'missing' => [null];
        yield 'forged' => ['forged-token'];
    }

    public function testACsrfTokenForAnotherOwnedOrderCannotCancelThisOrder(): void
    {
        $first = $this->order();
        $second = $this->order($first->customer());
        $this->client->loginUser($first->customer(), 'main');
        $token = $this->tokenFor($first);

        $this->client->request('POST', $this->cancelUrl($second), ['_token' => $token]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('placed', $this->persistedState($second));
    }

    #[DataProvider('blockedShipments')]
    public function testAStaleFormCannotCancelAPreparedOrDispatchedOrder(ShipmentState $state): void
    {
        $order = $this->order();
        $order->transitionTo(OrderState::Confirmed);
        $this->entityManager->flush();
        $this->client->loginUser($order->customer(), 'main');
        $token = $this->tokenFor($order);
        $shipment = Shipment::start($order, 'local_standard', 'Standart teslimat', 'manual', new \DateTimeImmutable());
        $shipment->markReady(null, 'TRACK-TEST', new \DateTimeImmutable());
        if (in_array($state, [ShipmentState::InTransit, ShipmentState::Delivered], true)) {
            $shipment->markInTransit(new \DateTimeImmutable());
        }
        if (ShipmentState::Delivered === $state) {
            $shipment->markDelivered(new \DateTimeImmutable());
        }
        $this->entityManager->persist($shipment);
        $this->entityManager->flush();
        $this->client->request('GET', $this->detailUrl($order));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="order-cancel"]');

        $this->client->request('POST', $this->cancelUrl($order), ['_token' => $token]);

        self::assertResponseRedirects($this->detailUrl($order));
        self::assertSame('confirmed', $this->persistedState($order));
        self::assertSame(0, $this->cancellationCount($order));
        $request = $this->client->getRequest();
        self::assertInstanceOf(Request::class, $request);
        $flashes = $request->getSession()->getBag('flashes');
        self::assertInstanceOf(FlashBagInterface::class, $flashes);
        self::assertStringContainsString('iptal edilemiyor', implode(' ', $flashes->peek('error')));
    }

    /** @return iterable<string, array{ShipmentState}> */
    public static function blockedShipments(): iterable
    {
        yield 'ready' => [ShipmentState::Ready];
        yield 'in transit' => [ShipmentState::InTransit];
        yield 'delivered' => [ShipmentState::Delivered];
    }

    public function testARefundFailureRedirectsWithAPublicMessageAndKeepsTheOrderConfirmed(): void
    {
        $order = $this->order();
        $gateway = self::getContainer()->get(FakePaymentGateway::class);
        $gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback('WEB-CAPTURE'));
        self::getContainer()->get(PaymentInitiationService::class)->start($order, 'fake');
        $payment = $this->entityManager->getRepository(Payment::class)->findOneBy(['order' => $order]);
        self::assertInstanceOf(Payment::class, $payment);
        $attempt = $payment->latestAttempt();
        self::assertNotNull($attempt);
        self::assertTrue(self::getContainer()->get(PaymentCallbackHandler::class)->handle($attempt->returnToken(), new IncomingPaymentCallback(http_build_query(['ref' => 'WEB-CAPTURE', 'outcome' => 'succeeded', 'amount' => '30000', 'currency' => 'TRY']), ['X-Fake-Signature' => 'valid'], []))->accepted());
        $gateway->queueRefund(GatewayRefundOutcome::failed(SanitizedFailure::fromProvider('private_provider_error', 'secret merchant_oid detail', null)));
        $this->client->loginUser($order->customer(), 'main');
        $token = $this->tokenFor($order);

        $this->client->request('POST', $this->cancelUrl($order), ['_token' => $token]);

        self::assertResponseRedirects($this->detailUrl($order));
        self::assertSame('confirmed', $this->persistedState($order));
        self::assertSame(0, $this->cancellationCount($order));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.storefront-flash-error', 'Ödeme iadesi tamamlanamadığı');
        self::assertSelectorTextNotContains('body', 'merchant_oid');
        self::assertSelectorTextNotContains('body', 'private_provider_error');
        self::assertSelectorExists('[data-testid="order-cancel"]');
    }

    private function tokenFor(CustomerOrder $order): string
    {
        $crawler = $this->client->request('GET', $this->detailUrl($order));
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('form[data-controller="order-cancel"] input[name="_token"]')->attr('value');
    }

    private function detailUrl(CustomerOrder $order): string
    {
        return '/yeni/hesabim/siparisler/'.$order->orderNumber();
    }

    private function cancelUrl(CustomerOrder $order): string
    {
        return $this->detailUrl($order).'/iptal';
    }

    private function persistedState(CustomerOrder $order): string
    {
        return (string) $this->connection->fetchOne('SELECT state FROM commerce_customer_order WHERE id = ?', [$order->id()]);
    }

    private function cancellationCount(CustomerOrder $order): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_order_status_change WHERE order_id = ? AND to_state = ?', [$order->id(), 'cancelled']);
    }

    private function order(?CustomerUser $customer = null): CustomerOrder
    {
        $suffix = strtoupper(bin2hex(random_bytes(6)));
        if (null === $customer) {
            $customer = new CustomerUser('cancel-web-'.strtolower($suffix).'@example.com', 'Efe', 'Yılmaz');
            $customer->setPassword('test-password-hash');
            $this->entityManager->persist($customer);
        }
        $gross = Money::ofMinor(30_000, 'TRY');
        $zero = Money::ofMinor(0, 'TRY');
        $order = new CustomerOrder('EOA-20261005-'.$suffix, $customer, $gross, $zero, $zero, $gross, 'local_standard', 'Standart teslimat', 'gateway_checkout', 'Kredi kartı', new \DateTimeImmutable());
        $order->addItem(null, 'CANCEL-WEB', 'Filtre', 3, Money::ofMinor(10_000, 'TRY'), 0, $gross, $zero, $gross);
        foreach ([OrderAddressRole::Shipping, OrderAddressRole::Billing] as $role) {
            $order->addAddress($role, 'Efe Yılmaz', '05320000000', 'Atatürk Caddesi 1', null, 'Çukurova', 'Adana', '01170', 'TR');
        }
        $order->sealSnapshots();
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }
}
