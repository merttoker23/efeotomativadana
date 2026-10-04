<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Payment;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\Shipment;
use App\Entity\Customer\CustomerUser;
use App\Message\CreateShipment;
use App\MessageHandler\CreateShipmentHandler;
use App\Module\Notification\NotificationType;
use App\Module\Notification\TransactionalNotificationService;
use App\Module\Order\AdminOrderManager;
use App\Module\Order\CustomerOrderCancellationService;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderCancellationService;
use App\Module\Order\OrderNotFound;
use App\Module\Order\OrderState;
use App\Module\Payment\FakePaymentGateway;
use App\Module\Payment\Gateway\GatewayInitiationOutcome;
use App\Module\Payment\Gateway\GatewayRefundOutcome;
use App\Module\Payment\Gateway\IncomingPaymentCallback;
use App\Module\Payment\PaymentCallbackHandler;
use App\Module\Payment\PaymentInitiationService;
use App\Module\Payment\PaymentRefundService;
use App\Module\Payment\PaymentRefundState;
use App\Module\Payment\PaymentState;
use App\Module\Payment\RefundRefused;
use App\Module\Payment\SanitizedFailure;
use App\Module\Shipping\ShipmentState;
use App\Module\Shipping\FakeShippingProvider;
use App\Module\Shipping\ShipmentOrchestrator;
use App\Shared\Money\Money;
use App\Tests\Integration\Notification\RecordingMailTransport;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Twig\Environment;

final class CustomerOrderCancellationTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private FakePaymentGateway $gateway;
    private CustomerOrderCancellationService $cancellations;
    private RecordingMailTransport $transport;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->connection->beginTransaction();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->transport = new RecordingMailTransport();
        $container->set(TransactionalNotificationService::class, new TransactionalNotificationService(
            $this->entityManager,
            new Mailer($this->transport),
            $container->get(Environment::class),
            $container->get('clock'),
        ));
        $this->gateway = $container->get(FakePaymentGateway::class);
        $this->cancellations = $container->get(CustomerOrderCancellationService::class);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testAnUnpaidOrderRestoresItsOrderedQuantityAndRecordsTheCustomer(): void
    {
        $order = $this->order();
        self::assertTrue($this->cancellations->canCancel($order));

        $cancelled = $this->cancellations->cancel($order->customer(), $order->orderNumber());

        self::assertSame($order->id(), $cancelled->id());
        $this->assertCancelledOnce($order);
        $history = $this->connection->fetchAssociative('SELECT actor_email, reason FROM commerce_order_status_change WHERE order_id = ? AND to_state = ?', [$order->id(), 'cancelled']);
        self::assertIsArray($history);
        self::assertSame($order->customerEmail(), $history['actor_email']);
        self::assertNotEmpty($history['reason']);
        $payload = $this->connection->fetchOne('SELECT payload FROM commerce_audit_log WHERE resource_id = ? AND action = ?', [$order->orderNumber(), 'order.state_changed']);
        self::assertIsString($payload);
        self::assertSame($order->customerEmail(), json_decode($payload, true, 512, JSON_THROW_ON_ERROR)['actor']);
        self::assertFalse($this->cancellations->canCancel($cancelled));
    }

    public function testAnUncapturedPaymentIsCancelledWithoutARefund(): void
    {
        $order = $this->order();
        $this->start($order);

        $this->cancellations->cancel($order->customer(), $order->orderNumber());

        self::assertSame(PaymentState::Cancelled, $this->payment($order)->state());
        self::assertCount(0, $this->payment($order)->refunds());
        $this->assertCancelledOnce($order);
    }

    public function testAFailedUncapturedPaymentCanBeCancelledWhileKeepingItsFailedAttempt(): void
    {
        $order = $this->order();
        $this->gateway->queueInitiation(GatewayInitiationOutcome::failed(SanitizedFailure::fromProvider('card_declined', 'Card declined', null)));
        self::getContainer()->get(PaymentInitiationService::class)->start($order, 'fake');
        self::assertSame(PaymentState::Failed, $this->payment($order)->state());

        $this->cancellations->cancel($order->customer(), $order->orderNumber());

        $payment = $this->payment($order);
        self::assertSame(PaymentState::Cancelled, $payment->state());
        self::assertSame(PaymentState::Failed, $payment->latestAttempt()?->state());
        self::assertCount(0, $payment->refunds());
        $this->assertCancelledOnce($order);
    }

    public function testEveryOutstandingAttemptIsCancelledAndLateProviderCallbacksAreReplays(): void
    {
        $order = $this->order();
        $this->start($order);
        $this->gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback('RETRY-'.$order->orderNumber()));
        self::getContainer()->get(PaymentInitiationService::class)->retry($order, 'fake');
        $attempts = $this->payment($order)->attempts();
        self::assertCount(2, $attempts);
        foreach ($attempts as $attempt) {
            self::assertTrue($attempt->state()->awaitsCallbackDecision());
        }

        $this->cancellations->cancel($order->customer(), $order->orderNumber());

        $handler = self::getContainer()->get(PaymentCallbackHandler::class);
        foreach ($attempts as $attempt) {
            self::assertSame(PaymentState::Cancelled, $attempt->state());
            foreach (['succeeded', 'failed', 'cancelled'] as $outcome) {
                $result = $handler->handle($attempt->returnToken(), $this->providerCallback((string) $attempt->providerReference(), $outcome, 'succeeded' === $outcome ? 30_000 : 0));
                self::assertTrue($result->replayed());
            }
        }
        self::assertSame(PaymentState::Cancelled, $this->payment($order)->state());
        self::assertSame(0, $this->payment($order)->capturedAmount()->minorAmount());
        $this->assertCancelledOnce($order);
    }

    public function testAProviderRefusingToReleaseAuthorizationKeepsTheOrderAndStock(): void
    {
        $order = $this->order();
        $this->start($order);
        $this->gateway->queueRelease(GatewayRefundOutcome::failed(SanitizedFailure::fromProvider('void_refused', 'Authorization cannot be released', null)));

        $this->expectException(\DomainException::class);
        try {
            $this->cancellations->cancel($order->customer(), $order->orderNumber());
        } finally {
            self::assertSame('placed', $this->connection->fetchOne('SELECT state FROM commerce_customer_order WHERE id = ?', [$order->id()]));
            self::assertSame('pending', $this->connection->fetchOne('SELECT state FROM commerce_payment WHERE order_id = ?', [$order->id()]));
            self::assertSame(7, $this->stock($order));
            self::assertSame(0, $this->cancelHistoryCount($order));
            self::assertCount(0, $this->cancellationEmails());
        }
    }

    #[DataProvider('paymentRestarts')]
    public function testPaymentCannotBeStartedOrRetriedAfterCancellation(string $operation): void
    {
        $order = $this->order();
        $this->cancellations->cancel($order->customer(), $order->orderNumber());
        // No initiation result is queued: reaching the provider is itself a test failure.
        $initiation = self::getContainer()->get(PaymentInitiationService::class);

        $this->expectException(\DomainException::class);
        try {
            if ('start' === $operation) {
                $initiation->start($order, 'fake');
            } else {
                $initiation->retry($order, 'fake');
            }
        } finally {
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_payment WHERE order_id = ?', [$order->id()]));
            $this->assertCancelledOnce($order);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function paymentRestarts(): iterable
    {
        yield 'start' => ['start'];
        yield 'retry' => ['retry'];
    }

    public function testCapturedFundsAreFullyRefundedBeforeCancellationAndAReplayDoesNotRefundAgain(): void
    {
        $order = $this->order();
        $this->capture($order);
        // Only one provider answer exists: an accidental second refund cannot silently succeed.
        $this->gateway->queueRefund(GatewayRefundOutcome::completed('REFUND-ONCE'));
        $this->cancellations->cancel($order->customer(), $order->orderNumber());

        $payment = $this->payment($order);
        self::assertSame(PaymentState::Refunded, $payment->state());
        self::assertSame(30_000, $payment->refundedAmount()->minorAmount());
        self::assertCount(1, $payment->refunds());
        self::assertSame(PaymentRefundState::Completed, $payment->refunds()[0]->state());
        $this->assertCancelledOnce($order);

        try {
            $this->cancellations->cancel($order->customer(), $order->orderNumber());
            self::fail('A duplicate customer cancellation must be refused.');
        } catch (\DomainException) {
            // A refused transactional command may close the entity manager; read durable facts.
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_payment_refund WHERE payment_id = ?', [$payment->id()]));
            $this->assertCancelledOnce($order);
        }
    }

    public function testCancellationRefundsOnlyTheRemainingBalanceAfterAPartialRefund(): void
    {
        $order = $this->order();
        $this->capture($order);
        $this->gateway->queueRefund(GatewayRefundOutcome::completed('PARTIAL'));
        self::getContainer()->get(PaymentRefundService::class)->refund($order, Money::ofMinor(10_000, 'TRY'), 'Partial return', 'admin@example.com');
        self::assertSame(OrderState::Confirmed, $order->state());
        self::assertSame(7, $this->stock($order));
        self::assertCount(0, $this->cancellationEmails());
        $this->gateway->queueRefund(GatewayRefundOutcome::completed('REMAINDER'));

        $this->cancellations->cancel($order->customer(), $order->orderNumber());

        $payment = $this->payment($order);
        self::assertCount(2, $payment->refunds());
        self::assertSame(20_000, $payment->refunds()[1]->amount()->minorAmount());
        self::assertSame(30_000, $payment->refundedAmount()->minorAmount());
        $this->assertCancelledOnce($order);
    }

    public function testCancellationRefundsTheRemainingInstallmentInterestAfterTheOrderAmountWasRefunded(): void
    {
        $order = $this->order();
        $this->start($order);
        $attempt = $this->payment($order)->latestAttempt();
        self::assertNotNull($attempt);
        self::assertTrue(self::getContainer()->get(PaymentCallbackHandler::class)->handle($attempt->returnToken(), $this->providerCallback((string) $attempt->providerReference(), 'succeeded', 33_000))->accepted());
        $this->gateway->queueRefund(GatewayRefundOutcome::completed('INSTALLMENT-PRINCIPAL'));
        self::getContainer()->get(PaymentRefundService::class)->refund($order, Money::ofMinor(30_000, 'TRY'), 'Order principal refunded', 'admin@example.com');
        $payment = $this->payment($order);
        self::assertSame(30_000, $payment->capturedAmount()->minorAmount());
        self::assertSame(33_000, $payment->collectedAmount()->minorAmount());
        self::assertSame(30_000, $payment->refundedAmount()->minorAmount());
        self::assertSame(3_000, $payment->refundableAmount()->minorAmount());
        self::assertSame(OrderState::Confirmed, $order->state());
        self::assertSame(7, $this->stock($order));
        self::assertCount(0, $this->cancellationEmails());
        $this->gateway->queueRefund(GatewayRefundOutcome::completed('INSTALLMENT-REMAINDER'));

        $this->cancellations->cancel($order->customer(), $order->orderNumber());

        self::assertSame(PaymentState::Refunded, $payment->state());
        self::assertSame(33_000, $payment->refundedAmount()->minorAmount());
        self::assertSame(0, $payment->refundableAmount()->minorAmount());
        self::assertCount(2, $payment->refunds());
        self::assertSame(3_000, $payment->refunds()[1]->amount()->minorAmount());
        $this->assertCancelledOnce($order);
    }

    public function testAProviderRefusalPreservesTheOrderAndStockAndCanBeRetried(): void
    {
        $order = $this->order();
        $this->capture($order);
        $this->gateway->queueRefund(GatewayRefundOutcome::failed(SanitizedFailure::fromProvider('refund_declined', 'provider refused 4111111111111111', null)));

        try {
            $this->cancellations->cancel($order->customer(), $order->orderNumber());
            self::fail('A failed refund must not cancel an order.');
        } catch (RefundRefused) {
            self::assertSame(OrderState::Confirmed, $order->state());
            self::assertSame(7, $this->stock($order));
            $payment = $this->payment($order);
            self::assertSame(PaymentState::Succeeded, $payment->state());
            self::assertSame(0, $payment->refundedAmount()->minorAmount());
            self::assertCount(1, $payment->refunds());
            self::assertSame(PaymentRefundState::Failed, $payment->refunds()[0]->state());
            self::assertStringNotContainsString('4111111111111111', (string) $payment->refunds()[0]->failure()?->message());
            self::assertSame(0, $this->cancelHistoryCount($order));
            self::assertCount(0, $this->cancellationEmails());
        }

        $this->gateway->queueRefund(GatewayRefundOutcome::completed('RETRY-REFUND'));
        $this->cancellations->cancel($order->customer(), $order->orderNumber());
        self::assertCount(2, $this->payment($order)->refunds());
        $this->assertCancelledOnce($order);
    }

    #[DataProvider('blockedShipments')]
    public function testPreparedOrDispatchedShipmentsCannotBeCancelled(ShipmentState $state): void
    {
        $order = $this->order();
        $this->capture($order);
        $this->shipment($order, $state);
        self::assertFalse($this->cancellations->canCancel($order));

        try {
            $this->cancellations->cancel($order->customer(), $order->orderNumber());
            self::fail('A parcel already prepared or dispatched must block cancellation.');
        } catch (\DomainException) {
            self::assertSame('confirmed', $this->connection->fetchOne('SELECT state FROM commerce_customer_order WHERE id = ?', [$order->id()]));
            self::assertSame(7, $this->stock($order));
            self::assertSame(0, $this->cancelHistoryCount($order));
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_payment_refund WHERE payment_id = ?', [$this->paymentId($order)]));
            self::assertCount(0, $this->cancellationEmails());
        }
    }

    /** @return iterable<string, array{ShipmentState}> */
    public static function blockedShipments(): iterable
    {
        yield 'ready' => [ShipmentState::Ready];
        yield 'in transit' => [ShipmentState::InTransit];
        yield 'delivered' => [ShipmentState::Delivered];
    }

    #[DataProvider('localShipments')]
    public function testLocalShipmentStatesPermitCancellation(ShipmentState $state): void
    {
        $order = $this->order();
        $order->transitionTo(OrderState::Confirmed);
        $shipment = $this->shipment($order, $state);
        self::assertTrue($this->cancellations->canCancel($order));

        $this->cancellations->cancel($order->customer(), $order->orderNumber());

        self::assertSame(ShipmentState::Cancelled, $shipment->state());
        $this->assertCancelledOnce($order);
    }

    /** @return iterable<string, array{ShipmentState}> */
    public static function localShipments(): iterable
    {
        yield 'pending' => [ShipmentState::Pending];
        yield 'failed' => [ShipmentState::Failed];
        yield 'cancelled' => [ShipmentState::Cancelled];
    }

    #[DataProvider('terminalOrders')]
    public function testTerminalOrdersCannotBeCancelled(OrderState $state): void
    {
        $order = $this->order();
        if (OrderState::Completed === $state) {
            $order->transitionTo(OrderState::Confirmed);
        }
        $order->transitionTo($state);
        $this->entityManager->flush();
        self::assertFalse($this->cancellations->canCancel($order));

        $this->expectException(\DomainException::class);
        try {
            $this->cancellations->cancel($order->customer(), $order->orderNumber());
        } finally {
            self::assertSame(7, $this->stock($order));
            self::assertSame(0, $this->cancelHistoryCount($order));
            self::assertCount(0, $this->cancellationEmails());
        }
    }

    /** @return iterable<string, array{OrderState}> */
    public static function terminalOrders(): iterable
    {
        yield 'cancelled' => [OrderState::Cancelled];
        yield 'completed' => [OrderState::Completed];
    }

    public function testAnotherCustomersOrderIsNotFoundAndCannotBeChanged(): void
    {
        $order = $this->order();
        $stranger = new CustomerUser('stranger-'.bin2hex(random_bytes(4)).'@example.com', 'Other', 'Customer');
        $stranger->setPassword('hash');
        $this->entityManager->persist($stranger);
        $this->entityManager->flush();

        $this->expectException(OrderNotFound::class);
        try {
            $this->cancellations->cancel($stranger, $order->orderNumber());
        } finally {
            self::assertSame('placed', $this->connection->fetchOne('SELECT state FROM commerce_customer_order WHERE id = ?', [$order->id()]));
            self::assertSame(7, $this->stock($order));
            self::assertSame(0, $this->cancelHistoryCount($order));
            self::assertCount(0, $this->cancellationEmails());
        }
    }

    public function testAReplayedGatewayCancellationRestoresStockAndAnnouncesOnlyOnce(): void
    {
        $order = $this->order();
        $this->start($order);
        $attempt = $this->payment($order)->latestAttempt();
        self::assertNotNull($attempt);
        $callback = $this->providerCallback((string) $attempt->providerReference(), 'cancelled', 0);
        $handler = self::getContainer()->get(PaymentCallbackHandler::class);

        self::assertTrue($handler->handle($attempt->returnToken(), $callback)->accepted());
        self::assertTrue($handler->handle($attempt->returnToken(), $callback)->replayed());

        self::assertSame(PaymentState::Cancelled, $this->payment($order)->state());
        $this->assertCancelledOnce($order);
    }

    public function testAProviderCancellationClosesEveryOpenAttemptBeforeLateCallbacksArrive(): void
    {
        $order = $this->order();
        $this->start($order);
        $this->gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback('PROVIDER-RETRY-'.$order->orderNumber()));
        self::getContainer()->get(PaymentInitiationService::class)->retry($order, 'fake');
        $payment = $this->payment($order);
        $attempts = $payment->attempts();
        self::assertCount(2, $attempts);
        $handler = self::getContainer()->get(PaymentCallbackHandler::class);

        self::assertTrue($handler->handle($attempts[0]->returnToken(), $this->providerCallback((string) $attempts[0]->providerReference(), 'cancelled', 0))->accepted());

        foreach ($attempts as $attempt) {
            self::assertSame(PaymentState::Cancelled, $attempt->state());
            foreach (['succeeded', 'failed', 'cancelled'] as $outcome) {
                self::assertTrue($handler->handle($attempt->returnToken(), $this->providerCallback((string) $attempt->providerReference(), $outcome, 'succeeded' === $outcome ? 30_000 : 0))->replayed());
            }
        }
        self::assertSame(PaymentState::Cancelled, $payment->state());
        self::assertSame(0, $payment->capturedAmount()->minorAmount());
        $this->assertCancelledOnce($order);
    }

    public function testAQueuedCarrierCreationDoesNotAdvanceAParcelAfterItsOrderIsCancelled(): void
    {
        $order = $this->order();
        $order->transitionTo(OrderState::Confirmed);
        $shipment = Shipment::start($order, 'carrier_express', 'Test carrier', 'fake', new \DateTimeImmutable());
        $this->entityManager->persist($shipment);
        $this->entityManager->flush();
        $provider = self::getContainer()->get(FakeShippingProvider::class);
        // Staff cancellation leaves this pending parcel in place, exercising the order guard.
        self::getContainer()->get(AdminOrderManager::class)->transition($order->orderNumber(), OrderState::Cancelled, 'Cancelled before dispatch', $order->version(), 'admin@example.com');
        self::assertSame(ShipmentState::Pending, $shipment->state());
        $handler = self::getContainer()->get(CreateShipmentHandler::class);
        $shipmentId = $shipment->id();
        self::assertNotNull($shipmentId);

        $handler(new CreateShipment($shipmentId));
        $handler(new CreateShipment($shipmentId));

        self::assertSame(0, $provider->createRequestCount());
        self::assertSame('pending', $this->connection->fetchOne('SELECT state FROM commerce_shipment WHERE id = ?', [$shipmentId]));
        self::assertNull($shipment->providerReference());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_shipment_event WHERE shipment_id = ?', [$shipmentId]));
        $this->assertCancelledOnce($order);
    }

    public function testAnAdminCannotHandOverAPendingParcelAfterTheOrderIsCancelled(): void
    {
        $order = $this->order();
        $order->transitionTo(OrderState::Confirmed);
        $shipment = $this->shipment($order, ShipmentState::Pending);
        self::getContainer()->get(AdminOrderManager::class)->transition($order->orderNumber(), OrderState::Cancelled, 'Cancelled before handover', $order->version(), 'admin@example.com');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('cancelled order cannot advance its shipment');
        try {
            self::getContainer()->get(ShipmentOrchestrator::class)->handOver($shipment, 'VAN-LATE', 'admin@example.com');
        } finally {
            self::assertSame('pending', $this->connection->fetchOne('SELECT state FROM commerce_shipment WHERE id = ?', [$shipment->id()]));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_shipment_event WHERE shipment_id = ?', [$shipment->id()]));
            $this->assertCancelledOnce($order);
        }
    }

    public function testAdminCancellationAndAReplayedFinalizerShareStockHistoryAndNotificationDeduplication(): void
    {
        $order = $this->order();
        self::getContainer()->get(AdminOrderManager::class)->transition($order->orderNumber(), OrderState::Cancelled, 'Customer called the store', $order->version(), 'admin@example.com');
        $finalizer = self::getContainer()->get(OrderCancellationService::class);
        $changed = $this->entityManager->wrapInTransaction(function () use ($order, $finalizer): bool {
            $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);

            return $finalizer->complete($order, 'Repeated cancellation', 'admin@example.com');
        });
        self::assertFalse($changed);
        $finalizer->announce($order);
        $finalizer->announce($order);

        $this->assertCancelledOnce($order);
    }

    public function testAStandaloneFullRefundAlsoRestoresStockAndSendsBothEmailAlternatives(): void
    {
        $order = $this->order();
        $this->capture($order);
        $this->gateway->queueRefund(GatewayRefundOutcome::completed('ADMIN-FULL'));

        self::getContainer()->get(PaymentRefundService::class)->refund($order, $order->grandTotal(), 'Full return', 'admin@example.com');

        $this->assertCancelledOnce($order);
        $email = $this->cancellationEmails()[0];
        self::assertSame([$order->customerEmail()], array_map(static fn ($address): string => $address->getAddress(), $email->getTo()));
        self::assertStringContainsString($order->orderNumber(), (string) $email->getTextBody());
        self::assertStringContainsString($order->orderNumber(), (string) $email->getHtmlBody());
        self::assertStringContainsString('iptal', (string) $email->getTextBody());
        self::assertStringContainsString('iptal', (string) $email->getHtmlBody());
    }

    private function assertCancelledOnce(CustomerOrder $order): void
    {
        self::assertSame('cancelled', $this->connection->fetchOne('SELECT state FROM commerce_customer_order WHERE id = ?', [$order->id()]));
        self::assertSame(10, $this->stock($order), 'Exactly the three reserved units must be returned.');
        self::assertSame(1, $this->cancelHistoryCount($order));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_notification WHERE type = ? AND subject_reference = ?', ['order_cancelled', $order->orderNumber()]));
        self::assertCount(1, $this->cancellationEmails());
    }

    /** @return list<Email> */
    private function cancellationEmails(): array
    {
        $emails = [];
        foreach ($this->transport->getSent() as $sent) {
            $email = $sent->getOriginalMessage();
            self::assertInstanceOf(Email::class, $email);
            if (NotificationType::OrderCancelled->subject() === $email->getSubject()) {
                $emails[] = $email;
            }
        }

        return $emails;
    }

    private function stock(CustomerOrder $order): int
    {
        return (int) $this->connection->fetchOne('SELECT quantity FROM commerce_product_inventory WHERE product_id = ?', [$order->items()[0]->product()?->id()]);
    }

    private function cancelHistoryCount(CustomerOrder $order): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_order_status_change WHERE order_id = ? AND to_state = ?', [$order->id(), 'cancelled']);
    }

    private function paymentId(CustomerOrder $order): int
    {
        return (int) $this->connection->fetchOne('SELECT id FROM commerce_payment WHERE order_id = ?', [$order->id()]);
    }

    private function payment(CustomerOrder $order): Payment
    {
        $payment = $this->entityManager->getRepository(Payment::class)->findOneBy(['order' => $order]);
        self::assertInstanceOf(Payment::class, $payment);

        return $payment;
    }

    private function start(CustomerOrder $order): void
    {
        $this->gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback('PAY-'.$order->orderNumber()));
        self::getContainer()->get(PaymentInitiationService::class)->start($order, 'fake');
    }

    private function capture(CustomerOrder $order): void
    {
        $this->start($order);
        $attempt = $this->payment($order)->latestAttempt();
        self::assertNotNull($attempt);
        self::assertTrue(self::getContainer()->get(PaymentCallbackHandler::class)->handle($attempt->returnToken(), $this->providerCallback((string) $attempt->providerReference(), 'succeeded', 30_000))->accepted());
    }

    private function providerCallback(string $reference, string $outcome, int $amount): IncomingPaymentCallback
    {
        return new IncomingPaymentCallback(http_build_query(['ref' => $reference, 'outcome' => $outcome, 'amount' => (string) $amount, 'currency' => 'TRY']), ['X-Fake-Signature' => 'valid'], []);
    }

    private function shipment(CustomerOrder $order, ShipmentState $state): Shipment
    {
        $at = new \DateTimeImmutable();
        $shipment = Shipment::start($order, 'local_standard', 'Standart teslimat', 'manual', $at);
        if (in_array($state, [ShipmentState::Ready, ShipmentState::InTransit, ShipmentState::Delivered], true)) {
            $shipment->markReady(null, 'TRACK-TEST', $at);
        }
        if (in_array($state, [ShipmentState::InTransit, ShipmentState::Delivered], true)) {
            $shipment->markInTransit($at);
        }
        if (ShipmentState::Delivered === $state) {
            $shipment->markDelivered($at);
        } elseif (ShipmentState::Failed === $state) {
            $shipment->markFailed(SanitizedFailure::fromProvider('creation_failed', null, null), $at);
        } elseif (ShipmentState::Cancelled === $state) {
            $shipment->markCancelled('Local parcel cancelled', $at);
        }
        $this->entityManager->persist($shipment);
        $this->entityManager->flush();

        return $shipment;
    }

    private function order(): CustomerOrder
    {
        $suffix = strtoupper(bin2hex(random_bytes(6)));
        $customer = new CustomerUser('cancel-'.strtolower($suffix).'@example.com', 'Efe', 'Yılmaz');
        $customer->setPassword('test-password-hash');
        $product = new Product('CANCEL-'.$suffix, 'Filtre', 'cancel-'.strtolower($suffix));
        $this->entityManager->persist($customer);
        $this->entityManager->persist($product);
        $this->entityManager->persist(new ProductInventory($product, 7));
        $gross = Money::ofMinor(30_000, 'TRY');
        $zero = Money::ofMinor(0, 'TRY');
        $order = new CustomerOrder('EOA-20261005-'.$suffix, $customer, $gross, $zero, $zero, $gross, 'local_standard', 'Standart teslimat', 'gateway_checkout', 'Kredi kartı', new \DateTimeImmutable());
        $order->addItem($product, $product->sku(), 'Filtre', 3, Money::ofMinor(10_000, 'TRY'), 0, $gross, $zero, $gross);
        foreach ([OrderAddressRole::Shipping, OrderAddressRole::Billing] as $role) {
            $order->addAddress($role, 'Efe Yılmaz', '05320000000', 'Atatürk Caddesi 1', null, 'Çukurova', 'Adana', '01170', 'TR');
        }
        $order->sealSnapshots();
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }
}
