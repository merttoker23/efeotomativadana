<?php

declare(strict_types=1);

namespace App\Module\Payment;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Payment;
use App\Module\Audit\AuditAction;
use App\Module\Audit\AuditLogger;
use App\Repository\Commerce\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The only payment actions an administrator may take by hand, and only where they are
 * semantically valid: retry a payment that has not been captured, or cancel one that was
 * never captured. A captured payment is never re-driven from the admin screens — it is
 * refunded through {@see PaymentRefundService} or left alone, because re-driving a capture
 * is how a store charges a customer twice.
 *
 * Both actions are audited with the actor and the reason. The aggregate records that a
 * payment was cancelled; only the audit row records that a person did it, that this payment
 * was their decision, and why.
 */
final readonly class PaymentAdminManager
{
    public function __construct(
        private PaymentGatewayRegistry $gateways,
        private PaymentRepository $payments,
        private PaymentInitiationService $initiation,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private AuditLogger $audit,
    ) {
    }

    /**
     * The retry always goes to the gateway the payment already belongs to, not to whatever
     * the store setting currently says. Switching providers must not move an order's money
     * from one provider to another halfway through.
     */
    public function retry(CustomerOrder $order, string $actorEmail): PaymentStartResult
    {
        $payment = $this->lockedPayment($order);
        if (!$payment->state()->canBeRetried()) {
            throw new \DomainException(sprintf('A %s payment cannot be retried.', $payment->state()->value));
        }

        $start = $this->initiation->retry($order, $payment->providerKey());
        $this->audit->record(
            AuditAction::PaymentRetried,
            $order->orderNumber(),
            ['provider' => $payment->providerKey(), 'from_state' => $payment->state()->value],
        );
        $this->entityManager->flush();

        return $start;
    }

    /**
     * Cancel an uncaptured payment. Refuses anything that has captured funds: that money has
     * to go back through a refund, which is a different and separately audited action.
     */
    public function cancel(CustomerOrder $order, string $reason, string $actorEmail): Payment
    {
        $reason = trim($reason);
        if ('' === $reason || mb_strlen($reason) > 255) {
            throw new \InvalidArgumentException('A payment cancellation requires a reason.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($order, $reason, $actorEmail): Payment {
            $this->entityManager->refresh($order, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $payment = $this->lockedPayment($order);
            if ($payment->state()->hasCapturedFunds()) {
                throw new \DomainException('A captured payment must be refunded, not cancelled.');
            }
            if (!$payment->state()->canBeRetried()) {
                throw new \DomainException(sprintf('A %s payment cannot be cancelled.', $payment->state()->value));
            }
            foreach ($payment->attempts() as $attempt) {
                if ($attempt->state()->awaitsCallbackDecision()) {
                    $this->releaseAtProvider($payment, $attempt);
                }
            }
            $payment->cancelUncaptured($reason, \DateTimeImmutable::createFromInterface($this->clock->now()));
            $this->payments->save($payment);
            $this->audit->record(
                AuditAction::PaymentCancelled,
                $order->orderNumber(),
                [
                    'provider' => $payment->providerKey(),
                    'attempt_sequence' => $payment->latestAttempt()?->sequence(),
                    'reason' => $reason,
                    // Recorded explicitly because the caller passes an e-mail the audit row
                    // cannot read for itself in a console context, where there is no session.
                    'actor' => $actorEmail,
                ],
            );
            $this->entityManager->flush();

            return $payment;
        });
    }

    /** A cancelled payment is no longer money the store is waiting for. */
    private function releaseAtProvider(Payment $payment, \App\Entity\Commerce\PaymentAttempt $attempt): void
    {
        $gateway = $this->gateways->resolve($payment->providerKey());
        if (null === $gateway || null === $attempt->providerReference()) {
            return;
        }
        // A provider that never authorized anything has nothing to release; the local state is
        // already the whole truth in that case.
        $outcome = $gateway->releaseAuthorization(new Gateway\GatewayRefundInstruction(
            $attempt->providerReference(),
            $payment->amount(),
            sprintf('void-%s-%d', $payment->order()->orderNumber(), $attempt->sequence()),
            'Store cancelled an uncaptured payment.',
        ));
        if (null !== $outcome && Gateway\RefundStatus::Completed !== $outcome->status()) {
            throw new \DomainException('The provider refused to release the payment authorization.');
        }
    }

    private function lockedPayment(CustomerOrder $order): Payment
    {
        return $this->payments->findOneForUpdate($order)
            ?? throw new PaymentNotFound(sprintf('Order %s has no payment.', $order->orderNumber()));
    }
}
