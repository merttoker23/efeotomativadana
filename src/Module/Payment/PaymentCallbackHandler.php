<?php

declare(strict_types=1);

namespace App\Module\Payment;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\OrderStatusChange;
use App\Entity\Commerce\Payment;
use App\Entity\Commerce\PaymentAttempt;
use App\Module\Order\OrderState;
use App\Module\Payment\Gateway\CallbackAuthentication;
use App\Module\Payment\Gateway\IncomingPaymentCallback;
use App\Repository\Commerce\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The one place a provider report is allowed to change anything.
 *
 * Three rules make it safe to call repeatedly, from a browser redirect and from a
 * server-to-server webhook at the same time:
 *
 * 1. the attempt is addressed only through its unguessable return token;
 * 2. the provider's own signature decides authenticity, never the request's claims;
 * 3. an attempt that already reached a terminal state absorbs a repeat without writing a
 *    second event, a second order status change or a second capture.
 */
final readonly class PaymentCallbackHandler
{
    public function __construct(
        private PaymentGatewayRegistry $gateways,
        private PaymentRepository $payments,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function handle(string $returnToken, IncomingPaymentCallback $callback): PaymentCallbackResult
    {
        $returnToken = trim($returnToken);
        if ('' === $returnToken) {
            return PaymentCallbackResult::rejected('unknown_attempt');
        }
        $attempt = $this->payments->findAttemptByReturnToken($returnToken);
        if (null === $attempt) {
            return PaymentCallbackResult::rejected('unknown_attempt');
        }
        $payment = $attempt->payment();

        $gateway = $this->gateways->resolve($payment->providerKey());
        if (null === $gateway) {
            return PaymentCallbackResult::rejected('provider_unavailable', $payment->state());
        }

        $authentication = $gateway->authenticateCallback($callback);
        if (!$authentication->verified()) {
            return PaymentCallbackResult::rejected($authentication->reason() ?? 'unverified', $payment->state());
        }

        return $this->entityManager->wrapInTransaction(function () use ($attempt, $authentication): PaymentCallbackResult {
            // Re-read under a row lock: two provider reports for the same payment must be
            // serialised, and the second must observe the first one's committed state rather
            // than its own stale snapshot.
            $locked = $this->payments->lockAttemptByReturnToken($attempt->returnToken());
            if (null === $locked) {
                return PaymentCallbackResult::rejected('unknown_attempt');
            }
            $lockedPayment = $locked->payment();
            $this->entityManager->refresh($lockedPayment);

            // A report for an attempt that no longer awaits a decision was already applied.
            // This is the replay guard: it runs before any write, so a repeated webhook can
            // never produce a second capture, a second event or a second order transition.
            if (!$locked->state()->awaitsCallbackDecision()) {
                return PaymentCallbackResult::replay($lockedPayment->state());
            }
            // Only meaningful once the attempt carries a reference. Some gateways issue one only
            // when the money moves, and rejecting those captures would strand real payments.
            $known = $locked->providerReference();
            if (null !== $known && $known !== $authentication->providerReference()) {
                return PaymentCallbackResult::rejected('reference_mismatch', $lockedPayment->state());
            }

            return match ($authentication->outcome()) {
                'succeeded' => $this->applyCapture($lockedPayment, $locked, $authentication),
                'failed' => $this->applyFailure($lockedPayment, $locked),
                'cancelled' => $this->applyCancellation($lockedPayment, $locked),
                default => PaymentCallbackResult::rejected('unknown_outcome', $lockedPayment->state()),
            };
        });
    }

    /** The attempt a return token addresses, for redirecting the customer to their order. */
    public function attemptFor(string $returnToken): ?PaymentAttempt
    {
        $returnToken = trim($returnToken);

        return '' === $returnToken ? null : $this->payments->findAttemptByReturnToken($returnToken);
    }

    private function applyCapture(Payment $payment, PaymentAttempt $attempt, CallbackAuthentication $authentication): PaymentCallbackResult
    {
        $captured = $authentication->capturedAmount();
        if (null === $captured) {
            return PaymentCallbackResult::rejected('missing_amount', $payment->state());
        }
        $now = $this->now();
        $reference = $authentication->providerReference() ?? $attempt->providerReference() ?? '';

        // The order total is the only amount that may mark an order paid. A provider that
        // reports a different figure still took real money, so the figure is recorded and the
        // payment stays refundable instead of being written off as "nothing captured".
        if (!$captured->equals($payment->amount())) {
            $payment->markCapturedAmountMismatch($attempt, $reference, $captured, $now);
            $this->payments->save($payment);
            $this->entityManager->flush();

            return PaymentCallbackResult::rejected('amount_mismatch', $payment->state());
        }

        $payment->markSucceeded($attempt, $reference, $captured, $now);
        $this->syncOrder($payment->order(), $now);
        $this->payments->save($payment);
        $this->entityManager->flush();

        return PaymentCallbackResult::applied($payment->state());
    }

    private function applyFailure(Payment $payment, PaymentAttempt $attempt): PaymentCallbackResult
    {
        $payment->markFailed($attempt, SanitizedFailure::fromProvider('payment_failed', 'The provider reported that the payment failed.', null), $this->now());
        $this->payments->save($payment);
        $this->entityManager->flush();

        return PaymentCallbackResult::applied($payment->state());
    }

    private function applyCancellation(Payment $payment, PaymentAttempt $attempt): PaymentCallbackResult
    {
        $now = $this->now();
        $payment->markCancelled($attempt, 'The customer cancelled at the provider.', $now);
        $this->cancelOrder($payment->order(), $now);
        $this->payments->save($payment);
        $this->entityManager->flush();

        return PaymentCallbackResult::applied($payment->state());
    }

    private function syncOrder(CustomerOrder $order, \DateTimeImmutable $at): void
    {
        if (OrderState::Placed !== $order->state()) {
            // A cancelled or already-confirmed order is left exactly as it is: the capture is
            // recorded, and staff reconcile it with a refund instead of a silent transition.
            return;
        }
        $order->transitionTo(OrderState::Confirmed);
        $this->entityManager->persist(new OrderStatusChange($order, OrderState::Placed, OrderState::Confirmed, 'Payment confirmed by the payment provider.', 'payment-gateway'));
    }

    private function cancelOrder(CustomerOrder $order, \DateTimeImmutable $at): void
    {
        if (OrderState::Placed !== $order->state()) {
            return;
        }
        $order->transitionTo(OrderState::Cancelled);
        $this->entityManager->persist(new OrderStatusChange($order, OrderState::Placed, OrderState::Cancelled, 'Payment cancelled at the payment provider.', 'payment-gateway'));
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
