<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\OrderStatusChange;
use App\Entity\Commerce\Payment;
use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderState;
use App\Module\Payment\FakePaymentGateway;
use App\Module\Payment\Gateway\GatewayInitiationOutcome;
use App\Module\Payment\Gateway\GatewayRefundOutcome;
use App\Module\Payment\Gateway\IncomingPaymentCallback;
use App\Module\Payment\PaymentCallbackHandler;
use App\Module\Payment\PaymentGatewayNotAvailable;
use App\Module\Payment\PaymentInitiationService;
use App\Module\Payment\PaymentNotFound;
use App\Module\Payment\PaymentRefundService;
use App\Module\Payment\PaymentRefundState;
use App\Module\Payment\PaymentStartResult;
use App\Module\Payment\PaymentState;
use App\Module\Payment\SanitizedFailure;
use App\Repository\Commerce\PaymentRepository;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PaymentOrchestrationTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private FakePaymentGateway $gateway;
    private PaymentInitiationService $initiation;
    private PaymentCallbackHandler $callbacks;
    private PaymentRefundService $refunds;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
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
        $refunds = $container->get(PaymentRefundService::class);
        self::assertInstanceOf(PaymentRefundService::class, $refunds);
        $this->refunds = $refunds;
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testAStartedPaymentRedirectsToTheGatewayAndBindsTheProviderReference(): void
    {
        $order = $this->order('flow@example.com', 45_000);
        $this->gateway->queueInitiation(GatewayInitiationOutcome::redirect('https://pay.test/hosted/a', 'FAKE-A'));

        $start = $this->initiation->start($order, 'FAKE');

        self::assertTrue($start->requiresRedirect());
        self::assertSame('https://pay.test/hosted/a', $start->redirectUrl());
        $payment = $this->paymentFor($order);
        self::assertSame(PaymentState::RequiresAction, $payment->state());
        self::assertCount(1, $payment->attempts());
        self::assertSame('FAKE-A', $payment->latestAttempt()?->providerReference());
    }

    public function testTheLocalOrderExistsBeforeAnyExternalInitiation(): void
    {
        $order = $this->order('order-first@example.com', 45_000);
        $this->gateway->queueInitiation(GatewayInitiationOutcome::redirect('https://pay.test/hosted/a', 'FAKE-B'));

        $this->initiation->start($order, 'FAKE');

        self::assertNotNull($order->id());
        self::assertSame(OrderState::Placed, $order->state());
        self::assertNotNull($this->paymentFor($order)->id());
    }

    public function testAHostedFormInitiationExposesItsActionUrlAndFields(): void
    {
        $order = $this->order('hosted@example.com', 45_000);
        $this->gateway->queueInitiation(GatewayInitiationOutcome::hostedForm('https://pay.test/hosted/form', ['merchant' => 'M-1'], 'FAKE-C'));

        $start = $this->initiation->start($order, 'FAKE');

        self::assertTrue($start->requiresRedirect());
        self::assertFalse($start->isRedirect());
        self::assertSame('https://pay.test/hosted/form', $start->redirectUrl());
        self::assertSame(['merchant' => 'M-1'], $start->hostedFormFields());
    }

    public function testAnImmediateGatewayFailureKeepsTheOrderAndRecordsSanitizedMetadata(): void
    {
        $order = $this->order('immediate-failure@example.com', 45_000);
        $this->gateway->queueInitiation(GatewayInitiationOutcome::failed(SanitizedFailure::fromProvider('card_declined', 'Declined 4111111111111111', null)));

        $start = $this->initiation->start($order, 'FAKE');

        self::assertTrue($start->isFailed());
        $payment = $this->paymentFor($order);
        self::assertSame(PaymentState::Failed, $payment->state());
        self::assertSame('card_declined', $payment->failure()?->code());
        self::assertStringNotContainsString('4111111111111111', (string) $payment->failure()->message());
        self::assertSame(OrderState::Placed, $order->state());
    }

    public function testRetryAfterAFailureCreatesANewAttemptInsteadOfMutatingTheOldOne(): void
    {
        $order = $this->order('retry@example.com', 45_000);
        $this->gateway->queueInitiation(GatewayInitiationOutcome::failed(SanitizedFailure::fromProvider('card_declined', 'no', null)));
        $this->initiation->start($order, 'FAKE');
        $this->gateway->queueInitiation(GatewayInitiationOutcome::redirect('https://pay.test/hosted/b', 'FAKE-D'));

        $this->initiation->retry($order, 'FAKE');

        $payment = $this->paymentFor($order);
        self::assertCount(2, $payment->attempts());
        self::assertSame(PaymentState::Failed, $payment->attempts()[0]->state());
        self::assertSame(PaymentState::RequiresAction, $payment->attempts()[1]->state());
        self::assertSame(2, $payment->attempts()[1]->sequence());
    }

    public function testARepeatedInitiationWithTheSameIdempotencyKeyDoesNotCreateASecondAttempt(): void
    {
        $order = $this->order('idem@example.com', 45_000);
        $this->gateway->queueInitiation(GatewayInitiationOutcome::redirect('https://pay.test/hosted/a', 'FAKE-E'));

        $first = $this->initiation->start($order, 'FAKE', 'idem-1');
        $second = $this->initiation->start($order, 'FAKE', 'idem-1');

        self::assertTrue($first->requiresRedirect());
        self::assertFalse($second->requiresRedirect(), 'A repeated initiation must not hand out a second hosted page.');
        self::assertSame('FAKE-E', $second->providerReference());
        self::assertCount(1, $this->paymentFor($order)->attempts());
    }

    public function testAnUnconfiguredProviderStartsNoPaymentAtAll(): void
    {
        $order = $this->order('unconfigured@example.com', 45_000);

        $this->expectException(PaymentGatewayNotAvailable::class);

        $this->initiation->start($order, null);
    }

    public function testAVerifiedSuccessCallbackConfirmsTheOrderExactlyOnce(): void
    {
        $order = $this->order('success@example.com', 45_000);
        $this->startAwaitingCallback($order, 'FAKE-OK', 45_000);

        $outcome = $this->callbacks->handle($this->tokenFor($order), $this->successCallback('FAKE-OK', 45_000));

        self::assertTrue($outcome->accepted());
        $payment = $this->paymentFor($order);
        self::assertSame(PaymentState::Succeeded, $payment->state());
        self::assertSame(45_000, $payment->capturedAmount()->minorAmount());
        self::assertSame(OrderState::Confirmed, $order->state());
        self::assertSame(1, $this->orderHistoryCount($order));
    }

    public function testAReplayedSuccessCallbackDoesNotDoubleTransitionOrDoubleRecordOrderHistory(): void
    {
        $order = $this->order('replay@example.com', 45_000);
        $this->startAwaitingCallback($order, 'FAKE-REPLAY', 45_000);
        $token = $this->tokenFor($order);
        $callback = $this->successCallback('FAKE-REPLAY', 45_000);

        self::assertTrue($this->callbacks->handle($token, $callback)->accepted());
        $eventsAfterFirst = count($this->paymentFor($order)->events());
        $replay = $this->callbacks->handle($token, $callback);

        self::assertFalse($replay->accepted(), 'A replayed callback must be reported as a replay, not applied again.');
        self::assertTrue($replay->replayed());
        $payment = $this->paymentFor($order);
        self::assertSame(PaymentState::Succeeded, $payment->state());
        self::assertSame(OrderState::Confirmed, $order->state());
        self::assertSame(1, $this->orderHistoryCount($order));
        self::assertCount($eventsAfterFirst, $payment->events(), 'A replay must not append another payment event.');
        self::assertSame(45_000, $payment->capturedAmount()->minorAmount(), 'A replay must not double-capture.');
    }

    public function testAReplayedFailureCallbackIsAlsoAbsorbed(): void
    {
        $order = $this->order('replay-failure@example.com', 45_000);
        $this->startAwaitingCallback($order, 'FAKE-REPLAY-FAIL', 45_000);
        $token = $this->tokenFor($order);
        $callback = $this->terminalCallback('FAKE-REPLAY-FAIL', 'failed');

        self::assertTrue($this->callbacks->handle($token, $callback)->accepted());
        $replay = $this->callbacks->handle($token, $callback);

        self::assertTrue($replay->replayed());
        self::assertSame(PaymentState::Failed, $this->paymentFor($order)->state());
        self::assertSame(OrderState::Placed, $order->state());
    }

    public function testAReplayedCancellationCallbackIsAlsoAbsorbed(): void
    {
        $order = $this->order('replay-cancel@example.com', 45_000);
        $this->startAwaitingCallback($order, 'FAKE-REPLAY-CANCEL', 45_000);
        $token = $this->tokenFor($order);
        $callback = $this->terminalCallback('FAKE-REPLAY-CANCEL', 'cancelled');

        self::assertTrue($this->callbacks->handle($token, $callback)->accepted());
        $replay = $this->callbacks->handle($token, $callback);

        self::assertTrue($replay->replayed());
        self::assertSame(PaymentState::Cancelled, $this->paymentFor($order)->state());
        self::assertSame(1, $this->orderHistoryCount($order));
    }

    public function testAnAmountMismatchCannotMarkAnOrderPaid(): void
    {
        $order = $this->order('mismatch@example.com', 45_000);
        $this->startAwaitingCallback($order, 'FAKE-SHORT', 45_000);

        $outcome = $this->callbacks->handle($this->tokenFor($order), $this->successCallback('FAKE-SHORT', 44_999));

        self::assertFalse($outcome->accepted());
        self::assertSame('amount_mismatch', $outcome->reason());
        $payment = $this->paymentFor($order);
        self::assertNotSame(PaymentState::Succeeded, $payment->state());
        self::assertSame(OrderState::Placed, $order->state());
        self::assertSame(0, $this->orderHistoryCount($order));
    }

    public function testAMismatchedCaptureStillRecordsTheMoneyTheProviderActuallyTook(): void
    {
        $order = $this->order('mismatch-recorded@example.com', 45_000);
        $this->startAwaitingCallback($order, 'FAKE-PARTIAL', 45_000);

        $outcome = $this->callbacks->handle($this->tokenFor($order), $this->successCallback('FAKE-PARTIAL', 30_000));

        self::assertFalse($outcome->accepted());
        self::assertSame('amount_mismatch', $outcome->reason());
        $payment = $this->paymentFor($order);
        // The provider may genuinely hold the customer's money, so the figure it reported is
        // persisted and the payment stays refundable. A mismatch is a reconciliation task, not
        // a reason to record "captured 0" and lose the money.
        self::assertSame(30_000, $payment->capturedAmount()->minorAmount());
        self::assertTrue($payment->state()->hasCapturedFunds());
        self::assertTrue($payment->state()->isRefundable());
        self::assertSame(OrderState::Placed, $order->state(), 'A mismatch must never confirm the order.');
    }

    public function testAForeignCurrencyCaptureCannotMarkAnOrderPaid(): void
    {
        $order = $this->order('currency@example.com', 45_000);
        $this->startAwaitingCallback($order, 'FAKE-EUR', 45_000);

        $outcome = $this->callbacks->handle($this->tokenFor($order), new IncomingPaymentCallback(
            http_build_query(['ref' => 'FAKE-EUR', 'outcome' => 'succeeded', 'amount' => '45000', 'currency' => 'EUR']),
            ['X-Fake-Signature' => 'valid'],
            [],
        ));

        self::assertFalse($outcome->accepted());
        $payment = $this->paymentFor($order);
        self::assertNotSame(PaymentState::Succeeded, $payment->state());
        self::assertSame('TRY', $payment->amount()->currency());
        self::assertSame(OrderState::Placed, $order->state());
    }

    public function testForgedCallbackDoesNotTouchThePayment(): void
    {
        $order = $this->order('forged@example.com', 45_000);
        $this->startAwaitingCallback($order, 'FAKE-FORGED', 45_000);

        $outcome = $this->callbacks->handle($this->tokenFor($order), $this->successCallback('FAKE-FORGED', 45_000, 'tampered'));

        self::assertFalse($outcome->accepted());
        self::assertSame('signature_mismatch', $outcome->reason());
        self::assertNotSame(PaymentState::Succeeded, $this->paymentFor($order)->state());
        self::assertSame(OrderState::Placed, $order->state());
    }

    public function testAnUnknownReturnTokenIsRejected(): void
    {
        $order = $this->order('unknown-token@example.com', 45_000);
        $this->startAwaitingCallback($order, 'FAKE-TOKEN', 45_000);

        $outcome = $this->callbacks->handle(str_repeat('a', 64), $this->successCallback('FAKE-TOKEN', 45_000));

        self::assertFalse($outcome->accepted());
        self::assertSame('unknown_attempt', $outcome->reason());
    }

    public function testACallbackSignedForAnotherPaymentIsRejected(): void
    {
        $victim = $this->order('victim@example.com', 45_000);
        $attacker = $this->order('attacker@example.com', 60_000);
        $this->startAwaitingCallback($victim, 'FAKE-VICTIM', 45_000);
        $this->startAwaitingCallback($attacker, 'FAKE-ATTACKER', 60_000);

        // A genuine, correctly signed callback for the attacker's payment, replayed at the
        // victim's token: the token decides which attempt is addressed, so it is refused.
        $outcome = $this->callbacks->handle($this->tokenFor($victim), $this->successCallback('FAKE-ATTACKER', 60_000));

        self::assertFalse($outcome->accepted());
        self::assertSame('reference_mismatch', $outcome->reason());
        self::assertSame(OrderState::Placed, $attacker->state());
        self::assertSame(OrderState::Placed, $victim->state());
    }

    public function testACaptureSettlesAnAttemptThatHadNoProviderReferenceAtInitiation(): void
    {
        $order = $this->order('late-reference@example.com', 45_000);
        // Some gateways only issue a reference when the money moves, so the attempt starts with
        // none. The callback's own reference must be accepted, or every such payment would be
        // refused as a mismatch and the captured money would never reach the order.
        $this->gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback());
        $this->initiation->start($order, 'FAKE');
        $payment = $this->paymentFor($order);
        self::assertNull($payment->latestAttempt()?->providerReference());

        $outcome = $this->callbacks->handle($this->tokenFor($order), $this->successCallback('FAKE-LATE-REF', 45_000, 'valid', $this->tokenFor($order)));

        self::assertTrue($outcome->accepted(), $outcome->reason());
        self::assertSame(PaymentState::Succeeded, $this->paymentFor($order)->state());
        self::assertSame('FAKE-LATE-REF', $this->paymentFor($order)->latestAttempt()?->providerReference());
        self::assertSame(OrderState::Confirmed, $order->state());
    }

    public function testARetryWorksWhenTheProviderAnswersWithTheSameReference(): void
    {
        $order = $this->order('same-reference@example.com', 45_000);
        $this->gateway->queueInitiation(GatewayInitiationOutcome::failed(SanitizedFailure::fromProvider('card_declined', 'no', null)));
        $this->initiation->start($order, 'FAKE');
        // A provider that honours the idempotency key answers a repeat with the SAME reference.
        // That is the normal case, so a new attempt must not collide with the earlier row.
        $this->gateway->queueInitiation(GatewayInitiationOutcome::redirect('https://pay.test/hosted/again', 'FAKE-SAME'));
        $this->initiation->retry($order, 'FAKE');
        $this->gateway->queueInitiation(GatewayInitiationOutcome::redirect('https://pay.test/hosted/again', 'FAKE-SAME'));
        $this->initiation->retry($order, 'FAKE');

        $payment = $this->paymentFor($order);
        self::assertCount(3, $payment->attempts());
        self::assertSame('FAKE-SAME', $payment->latestAttempt()?->providerReference());
        self::assertSame([1, 2, 3], array_map(static fn ($attempt): int => $attempt->sequence(), $payment->attempts()));
    }

    public function testAFailedCallbackLeavesTheOrderPlacedForRetry(): void
    {
        $order = $this->order('failed-callback@example.com', 45_000);
        $this->startAwaitingCallback($order, 'FAKE-FAILED', 45_000);

        $outcome = $this->callbacks->handle($this->tokenFor($order), $this->terminalCallback('FAKE-FAILED', 'failed'));

        self::assertTrue($outcome->accepted());
        self::assertSame(PaymentState::Failed, $this->paymentFor($order)->state());
        self::assertSame(OrderState::Placed, $order->state());
    }

    public function testAForgedCallbackIsRejectedWithoutTouchingThePayment(): void
    {
        $order = $this->order('forged@example.com', 45_000);
        $this->startAwaitingCallback($order, 'FAKE-FORGED', 45_000);

        $outcome = $this->callbacks->handle($this->tokenFor($order), $this->successCallback('FAKE-FORGED', 45_000, 'tampered'));

        self::assertFalse($outcome->accepted());
        self::assertSame('signature_mismatch', $outcome->reason());
        self::assertNotSame(PaymentState::Succeeded, $this->paymentFor($order)->state());
        self::assertSame(OrderState::Placed, $order->state());
    }

    public function testACancelledCallbackCancelsThePaymentAndTheOrder(): void
    {
        $order = $this->order('cancelled-callback@example.com', 45_000);
        $this->startAwaitingCallback($order, 'FAKE-CANCEL', 45_000);

        $outcome = $this->callbacks->handle($this->tokenFor($order), $this->terminalCallback('FAKE-CANCEL', 'cancelled'));

        self::assertTrue($outcome->accepted());
        self::assertSame(PaymentState::Cancelled, $this->paymentFor($order)->state());
        self::assertSame(OrderState::Cancelled, $order->state());
    }

    public function testAnAbandonedPaymentIsCancelledLocallyAndTheOrderSurvives(): void
    {
        $order = $this->order('abandoned@example.com', 45_000);
        $this->startAwaitingCallback($order, 'FAKE-ABANDON', 45_000);

        $this->initiation->markAbandoned($this->initiation->attemptForReturnToken($this->tokenFor($order)));

        self::assertSame(PaymentState::Cancelled, $this->paymentFor($order)->state());
        self::assertSame(OrderState::Placed, $order->state());
    }

    public function testAPartialRefundKeepsTheOrderConfirmedAndThePaymentPartiallyRefunded(): void
    {
        $order = $this->order('partial-refund@example.com', 40_000);
        $this->capture($order, 'FAKE-PARTIAL', 40_000);
        $this->gateway->queueRefund(GatewayRefundOutcome::completed('FAKE-R-1'));

        $refund = $this->refunds->refund($order, Money::ofMinor(15_000, 'TRY'), 'damaged part', 'admin@example.com');

        self::assertSame(PaymentRefundState::Completed, $refund->state());
        $payment = $this->paymentFor($order);
        self::assertSame(PaymentState::PartiallyRefunded, $payment->state());
        self::assertSame(25_000, $payment->refundableAmount()->minorAmount());
        self::assertSame(OrderState::Confirmed, $order->state());
    }

    public function testAProviderRejectedRefundIsStillRecordedWithItsFailure(): void
    {
        $order = $this->order('refund-rejected@example.com', 40_000);
        $this->capture($order, 'FAKE-REJ', 40_000);
        $this->gateway->queueRefund(GatewayRefundOutcome::failed(SanitizedFailure::fromProvider('not_refundable', 'Too late 4111111111111111', null)));

        try {
            $this->refunds->refund($order, Money::ofMinor(10_000, 'TRY'), 'too late', 'admin@example.com');
            self::fail('A refused refund must not be reported as completed.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('not_refundable', $exception->getMessage());
        }

        $payment = $this->paymentFor($order);
        // The provider's refusal is a fact about this payment and belongs in its history,
        // sanitized. Silently writing nothing leaves an operator with no record of the attempt.
        self::assertCount(1, $payment->refunds());
        $refund = $payment->refunds()[0];
        self::assertSame(PaymentRefundState::Failed, $refund->state());
        self::assertSame('not_refundable', $refund->failure()->code());
        self::assertStringNotContainsString('4111111111111111', (string) $refund->failure()->message());
        self::assertSame(0, $payment->refundedAmount()->minorAmount());
        self::assertSame(PaymentState::Succeeded, $payment->state());
    }

    public function testAFullRefundMovesThePaymentToRefundedAndCancelsTheOrder(): void
    {
        $order = $this->order('full-refund@example.com', 40_000);
        $this->capture($order, 'FAKE-FULL', 40_000);
        $this->gateway->queueRefund(GatewayRefundOutcome::completed('FAKE-R-2'));

        $this->refunds->refund($order, Money::ofMinor(40_000, 'TRY'), 'returned', 'admin@example.com');

        $payment = $this->paymentFor($order);
        self::assertSame(PaymentState::Refunded, $payment->state());
        self::assertSame(OrderState::Cancelled, $order->state());
    }

    public function testARejectedProviderRefundLeavesTheCapturedAmountIntact(): void
    {
        $order = $this->order('refund-failed@example.com', 40_000);
        $this->capture($order, 'FAKE-REFUND-FAIL', 40_000);
        $this->gateway->queueRefund(GatewayRefundOutcome::failed(SanitizedFailure::fromProvider('not_refundable', 'too late 4111111111111111', null)));

        $this->expectException(\DomainException::class);
        try {
            $this->refunds->refund($order, Money::ofMinor(10_000, 'TRY'), 'too late', 'admin@example.com');
        } finally {
            $payment = $this->paymentFor($order);
            self::assertSame(PaymentState::Succeeded, $payment->state());
            self::assertSame(0, $payment->refundedAmount()->minorAmount());
            self::assertSame(OrderState::Confirmed, $order->state());
        }
    }

    public function testARefundCannotExceedTheCapturedAmount(): void
    {
        $order = $this->order('over-refund@example.com', 40_000);
        $this->capture($order, 'FAKE-OVER', 40_000);
        $this->gateway->queueRefund(GatewayRefundOutcome::completed('FAKE-R-3'));
        $this->refunds->refund($order, Money::ofMinor(30_000, 'TRY'), 'partial', 'admin@example.com');
        $this->gateway->queueRefund(GatewayRefundOutcome::completed('FAKE-R-4'));

        $this->expectException(\DomainException::class);
        $this->refunds->refund($order, Money::ofMinor(10_001, 'TRY'), 'too much', 'admin@example.com');
    }

    public function testAnUncapturedPaymentCannotBeRefunded(): void
    {
        $order = $this->order('uncaptured-refund@example.com', 40_000);
        $this->startAwaitingCallback($order, 'FAKE-NOCAPTURE', 40_000);

        $this->expectException(\DomainException::class);
        $this->refunds->refund($order, Money::ofMinor(1_000, 'TRY'), 'nope', 'admin@example.com');
    }

    public function testRefundingAnOrderWithoutAPaymentIsRejected(): void
    {
        $order = $this->order('no-payment@example.com', 40_000);

        $this->expectException(PaymentNotFound::class);
        $this->refunds->refund($order, Money::ofMinor(1_000, 'TRY'), 'nope', 'admin@example.com');
    }

    public function testARefundWithoutAReasonIsRejected(): void
    {
        $order = $this->order('no-reason@example.com', 40_000);
        $this->capture($order, 'FAKE-NOREASON', 40_000);
        $this->gateway->queueRefund(GatewayRefundOutcome::completed('FAKE-R-5'));

        $this->expectException(\InvalidArgumentException::class);
        $this->refunds->refund($order, Money::ofMinor(1_000, 'TRY'), '   ', 'admin@example.com');
    }

    public function testPaymentHistoryStaysSeparateFromOrderHistory(): void
    {
        $order = $this->order('separate@example.com', 45_000);
        $this->capture($order, 'FAKE-SEPARATE', 45_000);
        $this->gateway->queueRefund(GatewayRefundOutcome::completed('FAKE-R-SEP'));
        $this->refunds->refund($order, Money::ofMinor(5_000, 'TRY'), 'partial return', 'admin@example.com');

        $orderHistory = $this->entityManager->getRepository(OrderStatusChange::class)->findBy(['order' => $order]);
        $paymentHistory = $this->paymentFor($order)->events();

        // The refund is a payment fact, not an order transition: the order timeline holds only
        // the capture confirmation while the payment trail holds the money movements.
        self::assertCount(1, $orderHistory);
        self::assertCount(3, $paymentHistory);
        self::assertSame('payment', $paymentHistory[0]->source());
        self::assertSame('payment-gateway', $orderHistory[0]->actorEmail());
        self::assertCount(1, $this->paymentFor($order)->refunds());
    }

    public function testASuccessfulCaptureConfirmsAnOrderOnlyWhenItIsStillPlaced(): void
    {
        $order = $this->order('not-placed@example.com', 45_000);
        $order->transitionTo(OrderState::Cancelled);
        $this->startAwaitingCallback($order, 'FAKE-LATE', 45_000);

        $this->callbacks->handle($this->tokenFor($order), $this->successCallback('FAKE-LATE', 45_000));

        self::assertSame(PaymentState::Succeeded, $this->paymentFor($order)->state(), 'The capture is real and must be recorded.');
        self::assertSame(OrderState::Cancelled, $order->state(), 'A cancelled order must not be silently confirmed; staff reconcile it with a refund.');
    }

    private function capture(CustomerOrder $order, string $reference, int $amount): void
    {
        $this->startAwaitingCallback($order, $reference, $amount);
        self::assertTrue($this->callbacks->handle($this->tokenFor($order), $this->successCallback($reference, $amount))->accepted());
    }

    private function startAwaitingCallback(CustomerOrder $order, string $reference, int $amount): PaymentStartResult
    {
        $this->gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback($reference));

        return $this->initiation->start($order, 'FAKE');
    }

    private function orderHistoryCount(CustomerOrder $order): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_order_status_change WHERE order_id = ?', [$order->id()]);
    }

    private function successCallback(string $reference, int $amount, string $signature = 'valid', string $returnToken = ''): IncomingPaymentCallback
    {
        return new IncomingPaymentCallback(
            http_build_query(['ref' => $reference, 'outcome' => 'succeeded', 'amount' => (string) $amount, 'currency' => 'TRY']),
            ['X-Fake-Signature' => $signature],
            [],
            $returnToken,
        );
    }

    private function terminalCallback(string $reference, string $outcome): IncomingPaymentCallback
    {
        return new IncomingPaymentCallback(
            http_build_query(['ref' => $reference, 'outcome' => $outcome, 'amount' => '0', 'currency' => 'TRY']),
            ['X-Fake-Signature' => 'valid'],
            [],
        );
    }

    private function paymentFor(CustomerOrder $order): Payment
    {
        $payment = $this->repository()->findOneForOrder($order);
        self::assertInstanceOf(Payment::class, $payment);

        return $payment;
    }

    private function tokenFor(CustomerOrder $order): string
    {
        $payment = $this->paymentFor($order);
        $attempt = $payment->latestAttempt();
        self::assertNotNull($attempt);

        return $attempt->returnToken();
    }

    private function repository(): PaymentRepository
    {
        $repository = self::getContainer()->get('doctrine')->getRepository(Payment::class);
        self::assertInstanceOf(PaymentRepository::class, $repository);

        return $repository;
    }

    private function order(string $email, int $grandTotalMinor): CustomerOrder
    {
        $customer = new CustomerUser($email, 'Efe', 'Yılmaz');
        $customer->setPassword('test-password-hash');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $zero = Money::ofMinor(0, 'TRY');
        $gross = Money::ofMinor($grandTotalMinor, 'TRY');
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
        $order->addItem(null, 'PAY-SKU', 'Ödeme Ürünü', 1, $gross, 0, $gross, $zero, $gross);
        $order->addAddress(OrderAddressRole::Shipping, 'Efe Yılmaz', '05000000000', 'Cadde 1', null, 'Seyhan', 'Adana', null, 'TR');
        $order->addAddress(OrderAddressRole::Billing, 'Efe Yılmaz', '05000000000', 'Cadde 1', null, 'Seyhan', 'Adana', null, 'TR');
        $order->sealSnapshots();
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }
}
