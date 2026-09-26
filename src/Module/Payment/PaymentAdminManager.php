<?php

declare(strict_types=1);

namespace App\Module\Payment;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Payment;
use App\Repository\Commerce\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The only payment actions an administrator may take by hand, and only where they are
 * semantically valid: retry a payment that has not been captured, or cancel one that was
 * never captured. A captured payment is never re-driven from the admin screens — it is
 * refunded through {@see PaymentRefundService} or left alone, because re-driving a capture
 * is how a store charges a customer twice.
 */
final readonly class PaymentAdminManager
{
    public function __construct(
        private PaymentGatewayRegistry $gateways,
        private PaymentRepository $payments,
        private PaymentInitiationService $initiation,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
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

        return $this->initiation->retry($order, $payment->providerKey());
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

        return $this->entityManager->wrapInTransaction(function () use ($order, $reason): Payment {
            $payment = $this->lockedPayment($order);
            if ($payment->state()->hasCapturedFunds()) {
                throw new \DomainException('A captured payment must be refunded, not cancelled.');
            }
            if (!$payment->state()->canBeRetried()) {
                throw new \DomainException(sprintf('A %s payment cannot be cancelled.', $payment->state()->value));
            }
            $attempt = $payment->latestAttempt();
            if (null === $attempt) {
                throw new PaymentNotFound(sprintf('Order %s has no payment attempt to cancel.', $order->orderNumber()));
            }

            $this->releaseAtProvider($payment, $attempt);
            $payment->markCancelled($attempt, $reason, \DateTimeImmutable::createFromInterface($this->clock->now()));
            $this->payments->save($payment);
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
        $gateway->releaseAuthorization(new Gateway\GatewayRefundInstruction(
            $attempt->providerReference(),
            $payment->amount(),
            sprintf('void-%s-%d', $payment->order()->orderNumber(), $attempt->sequence()),
            'Store cancelled an uncaptured payment.',
        ));
    }

    private function lockedPayment(CustomerOrder $order): Payment
    {
        return $this->payments->findOneForUpdate($order)
            ?? throw new PaymentNotFound(sprintf('Order %s has no payment.', $order->orderNumber()));
    }
}
