<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\Payment;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Payment;
use App\Entity\Commerce\PaymentRefund;
use App\Entity\Customer\AdminUser;
use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderState;
use App\Module\Payment\FakePaymentGateway;
use App\Module\Payment\Gateway\GatewayInitiationOutcome;
use App\Module\Payment\Gateway\GatewayRefundOutcome;
use App\Module\Payment\Gateway\IncomingPaymentCallback;
use App\Module\Payment\PaymentCallbackHandler;
use App\Module\Payment\PaymentInitiationService;
use App\Module\Payment\PaymentState;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class PaymentAdminTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private FakePaymentGateway $gateway;
    private PaymentInitiationService $initiation;
    private PaymentCallbackHandler $callbacks;

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
        $initiation = $container->get(PaymentInitiationService::class);
        self::assertInstanceOf(PaymentInitiationService::class, $initiation);
        $this->initiation = $initiation;
        $callbacks = $container->get(PaymentCallbackHandler::class);
        self::assertInstanceOf(PaymentCallbackHandler::class, $callbacks);
        $this->callbacks = $callbacks;
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testAnAnonymousVisitorCannotReachThePaymentList(): void
    {
        $this->client->request('GET', '/yeni/admin/odemeler');

        self::assertResponseRedirects();
    }

    public function testANonAdminCannotReachThePaymentList(): void
    {
        // An authenticated non-admin identity on the admin firewall still gets nothing.
        $this->client->loginUser(new InMemoryUser(
            'viewer@example.com',
            'test-only-not-used-for-form-login',
            ['ROLE_USER'],
        ), 'admin');

        $this->client->request('GET', '/yeni/admin/odemeler');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheListShowsPaymentsWithTheirOrderAndProviderReference(): void
    {
        $order = $this->order('list@example.com');
        $this->startAwaitingCallback($order, 'FAKE-LIST');
        $this->loginAsAdmin();

        $this->client->request('GET', '/yeni/admin/odemeler');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($order->orderNumber(), $content);
        self::assertStringContainsString('FAKE-LIST', $content);
        self::assertStringContainsString('requires_action', $content);
    }

    public function testTheListCanBeFilteredByState(): void
    {
        $pending = $this->order('filter-pending@example.com');
        $this->startAwaitingCallback($pending, 'FAKE-PENDING');
        $succeeded = $this->order('filter-succeeded@example.com');
        $this->capture($succeeded, 'FAKE-SUCCEEDED');
        $this->loginAsAdmin();

        $this->client->request('GET', '/yeni/admin/odemeler?state=succeeded');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($succeeded->orderNumber(), $content);
        self::assertStringNotContainsString($pending->orderNumber(), $content);
    }

    public function testAnUnknownStateFilterIsIgnoredRatherThanFatal(): void
    {
        $this->order('bogus-filter@example.com');
        $this->loginAsAdmin();

        $this->client->request('GET', '/yeni/admin/odemeler?state=not-a-state');

        self::assertResponseIsSuccessful();
    }

    public function testTheDetailPageSeparatesPaymentHistoryFromOrderHistory(): void
    {
        $order = $this->order('detail@example.com');
        $this->capture($order, 'FAKE-DETAIL');
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/odemeler/%s', $order->orderNumber()));

        self::assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        // The provider reference lives in the payment section, the order link in the header.
        self::assertStringContainsString('FAKE-DETAIL', (string) $crawler->filter('table')->text());
        self::assertStringContainsString('succeeded', (string) $crawler->filter('body')->text());
        self::assertGreaterThan(0, $crawler->filter('table')->count(), 'The payment history tables must render.');
        // The screen whose whole job is auditing money must show money, not only raw minor
        // units an operator has to divide by hand. The exact figure is kept alongside it
        // because that is what the provider's own dashboard shows.
        $summary = (string) $crawler->filter('dl')->text();
        self::assertStringContainsString('300,00', $summary);
        self::assertStringContainsString('30000 minor units', $summary);
        self::assertGreaterThan(0, $crawler->filter(sprintf('a[href$="/yeni/admin/orders/%s"]', $order->orderNumber()))->count(), 'The payment page must link back to the order it belongs to.');
    }

    public function testRetryIsOfferedOnlyForARetryablePayment(): void
    {
        $order = $this->order('retry-pending@example.com');
        $this->startAwaitingCallback($order, 'FAKE-RETRY');
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/odemeler/%s', $order->orderNumber()));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->client->getCrawler()->filter('form[action$="yeniden-dene"]'));
    }

    public function testRetryIsNotOfferedForACapturedPayment(): void
    {
        $order = $this->order('retry-captured@example.com');
        $this->capture($order, 'FAKE-NORETRY');
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/odemeler/%s', $order->orderNumber()));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->client->getCrawler()->filter('form[action$="yeniden-dene"]'));
    }

    public function testCancellingAnUncapturedPaymentIsAllowedAndRecorded(): void
    {
        $order = $this->order('cancel@example.com');
        $this->startAwaitingCallback($order, 'FAKE-CANCEL');
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/odemeler/%s', $order->orderNumber()));
        $form = $this->client->getCrawler()->filter(sprintf('form[action$="%s/iptal"]', $order->orderNumber()))->form();
        $form['payment_cancel[reason]'] = 'Müşteri talebiyle iptal edildi';
        $this->client->submit($form);

        self::assertResponseRedirects(sprintf('/yeni/admin/odemeler/%s', $order->orderNumber()));
        self::assertSame(PaymentState::Cancelled, $this->paymentFor($this->reload($order))->state());
    }

    public function testACapturedPaymentCannotBeCancelledEvenWithAValidToken(): void
    {
        $order = $this->order('cancel-captured@example.com');
        $this->capture($order, 'FAKE-CANCEL-CAPTURED');
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/odemeler/%s', $order->orderNumber()));
        self::assertResponseIsSuccessful();
        // The page must not offer the action at all...
        self::assertCount(0, $this->client->getCrawler()->filter(sprintf('form[action$="%s/iptal"]', $order->orderNumber())));

        // ...and a correctly authenticated, correctly CSRF-tokened operator must still be
        // refused. Otherwise the guard is only the hidden button, which a crafted request walks
        // straight past.
        // The cancel form is not rendered for a captured payment, so a real token is taken
        // from a retryable order rendered in the same session.
        $other = $this->order('cancel-token-source@example.com');
        $this->startAwaitingCallback($other, 'FAKE-TOKEN-SOURCE');
        $this->client->request('GET', sprintf('/yeni/admin/odemeler/%s', $other->orderNumber()));
        $csrf = $this->client->getCrawler()->filter('input[name="payment_cancel[_token]"]')->attr('value');
        self::assertIsString($csrf);
        $this->client->request('POST', sprintf('/yeni/admin/odemeler/%s/iptal', $order->orderNumber()), [
            'payment_cancel' => ['reason' => 'Yanlışlıkla', '_token' => $csrf],
        ]);

        self::assertResponseStatusCodeSame(403);
        $payment = $this->paymentFor($this->reload($order));
        self::assertSame(PaymentState::Succeeded, $payment->state(), 'Captured money must never be cancelled by hand.');
        self::assertSame(0, $payment->refundedAmount()->minorAmount());
    }

    public function testCancellingRequiresACsrfToken(): void
    {
        $order = $this->order('cancel-csrf@example.com');
        $this->startAwaitingCallback($order, 'FAKE-CSRF');
        $this->loginAsAdmin();

        $this->client->request('POST', sprintf('/yeni/admin/odemeler/%s/iptal', $order->orderNumber()), [
            'payment_cancel' => ['reason' => 'forged', '_token' => 'not-a-real-token'],
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNotSame(PaymentState::Cancelled, $this->paymentFor($this->reload($order))->state());
    }

    public function testRetryingRequiresACsrfToken(): void
    {
        $order = $this->order('retry-csrf@example.com');
        $this->startAwaitingCallback($order, 'FAKE-RETRY-CSRF');
        $this->loginAsAdmin();

        $this->client->request('POST', sprintf('/yeni/admin/odemeler/%s/yeniden-dene', $order->orderNumber()), [
            'payment_retry' => ['_token' => 'not-a-real-token'],
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->paymentFor($this->reload($order))->attempts());
    }

    public function testCancellingRequiresAReason(): void
    {
        $order = $this->order('cancel-reason@example.com');
        $this->startAwaitingCallback($order, 'FAKE-REASON');
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/odemeler/%s', $order->orderNumber()));
        $form = $this->client->getCrawler()->filter(sprintf('form[action$="%s/iptal"]', $order->orderNumber()))->form();
        $form['payment_cancel[reason]'] = '   ';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('İptal nedeni zorunludur', (string) $this->client->getResponse()->getContent());
        self::assertNotSame(PaymentState::Cancelled, $this->paymentFor($this->reload($order))->state());
    }

    public function testRetryCreatesANewAttemptVisibleInThePaymentTrail(): void
    {
        $order = $this->order('admin-retry@example.com');
        $this->startAwaitingCallback($order, 'FAKE-ADMIN-RETRY');
        $this->gateway->queueInitiation(GatewayInitiationOutcome::redirect('https://pay.test/hosted/retry', 'FAKE-ADMIN-RETRY-2'));
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/odemeler/%s', $order->orderNumber()));
        $button = $this->client->getCrawler()->filter(sprintf('form[action$="%s/yeniden-dene"] button', $order->orderNumber()));
        self::assertSame(1, $button->count(), 'A retryable payment must offer a restart button.');
        $this->client->submit($button->form());

        self::assertResponseRedirects(sprintf('/yeni/admin/odemeler/%s', $order->orderNumber()));
        $payment = $this->paymentFor($this->reload($order));
        self::assertCount(2, $payment->attempts(), 'A restart must add an attempt, never replace one.');
        self::assertSame('FAKE-ADMIN-RETRY-2', $payment->latestAttempt()?->providerReference());
    }

    public function testARefundIsRecordedAgainstThePaymentAndNotTheOrderTimeline(): void
    {
        $order = $this->order('refund-admin@example.com');
        $this->capture($order, 'FAKE-REFUND');
        $this->gateway->queueRefund(GatewayRefundOutcome::completed('FAKE-REFUND-R'));
        $refunds = self::getContainer()->get(\App\Module\Payment\PaymentRefundService::class);
        self::assertInstanceOf(\App\Module\Payment\PaymentRefundService::class, $refunds);
        $refunds->refund($order, Money::ofMinor(10_000, 'TRY'), 'Kısmi iade', 'admin@example.com');
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/odemeler/%s', $order->orderNumber()));

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('FAKE-REFUND-R', $content);
        self::assertStringContainsString('Kısmi iade', $content);
        self::assertStringContainsString('partially_refunded', $content);
    }

    public function testThePaymentListIsReachableFromTheAdminNavigation(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/yeni/admin');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/yeni/admin/odemeler', (string) $this->client->getResponse()->getContent());
    }

    public function testFailureMetadataIsShownWithoutSecrets(): void
    {
        $order = $this->order('failure-display@example.com');
        $this->gateway->queueInitiation(GatewayInitiationOutcome::failed(\App\Module\Payment\SanitizedFailure::fromProvider('card_declined', 'Declined 4111111111111111 cvc=123', null)));
        $this->initiation->start($order, 'FAKE');
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/odemeler/%s', $order->orderNumber()));

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('card_declined', $content);
        self::assertStringNotContainsString('4111111111111111', $content);
        self::assertStringNotContainsString('cvc=123', $content);
    }

    public function testTheStoreOrderPageLinksToThePaymentTrail(): void
    {
        $order = $this->order('link@example.com');
        $this->capture($order, 'FAKE-LINK');
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/orders/%s', $order->orderNumber()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(sprintf('/yeni/admin/odemeler/%s', $order->orderNumber()), (string) $this->client->getResponse()->getContent());
    }

    private function loginAsAdmin(): void
    {
        $admin = new AdminUser('admin@example.com');
        $admin->setPassword('test-password-hash');
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        $this->client->loginUser($admin, 'admin');
    }

    private function startAwaitingCallback(CustomerOrder $order, string $reference): void
    {
        $this->gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback($reference));
        $this->initiation->start($order, 'FAKE');
    }

    private function capture(CustomerOrder $order, string $reference): void
    {
        $this->startAwaitingCallback($order, $reference);
        $payment = $this->paymentFor($order);
        self::assertInstanceOf(Payment::class, $payment);
        $attempt = $payment->latestAttempt();
        self::assertNotNull($attempt);
        $this->callbacks->handle($attempt->returnToken(), new IncomingPaymentCallback(
            http_build_query(['ref' => $reference, 'outcome' => 'succeeded', 'amount' => '30000', 'currency' => 'TRY']),
            ['X-Fake-Signature' => 'valid'],
            [],
        ));
    }

    private function reload(CustomerOrder $order): CustomerOrder
    {
        $this->entityManager->clear();

        return $this->entityManager->find(CustomerOrder::class, $order->id());
    }

    private function paymentFor(CustomerOrder $order): ?Payment
    {
        $repository = self::getContainer()->get('doctrine')->getRepository(Payment::class);
        self::assertInstanceOf(\App\Repository\Commerce\PaymentRepository::class, $repository);

        return $repository->findOneForOrder($order);
    }

    private function customer(string $email): CustomerUser
    {
        $customer = new CustomerUser($email, 'Efe', 'Yılmaz');
        $customer->setPassword('test-password-hash');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function order(string $email): CustomerOrder
    {
        $customer = $this->customer($email);

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
        $order->addItem(null, 'ADMIN-SKU', 'Ödeme Ürünü', 1, $gross, 0, $gross, $zero, $gross);
        $order->addAddress(OrderAddressRole::Shipping, 'Efe Yılmaz', '05000000000', 'Atatürk Cad. 1', null, 'Seyhan', 'Adana', null, 'TR');
        $order->addAddress(OrderAddressRole::Billing, 'Efe Yılmaz', '05000000000', 'Atatürk Cad. 1', null, 'Seyhan', 'Adana', null, 'TR');
        $order->sealSnapshots();
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }
}
