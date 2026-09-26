<?php

declare(strict_types=1);

namespace App\Module\Payment;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\OrderStatusChange;
use App\Entity\Commerce\Payment;
use App\Entity\Commerce\PaymentRefund;
use App\Module\Order\OrderState;
use App\Module\Payment\Gateway\GatewayRefundInstruction;
use App\Module\Payment\Gateway\GatewayRefundOutcome;
use App\Module\Payment\Gateway\RefundStatus;
use App\Repository\Commerce\PaymentRepository;
use App\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Partial and full refunds of a captured payment.
 *
 * The provider call happens first: if the money did not go back, nothing local changes, so
 * the store can never display a refund that the provider refused.
 */
final readonly class PaymentRefundService
{
    public function __construct(
        private PaymentGatewayRegistry $gateways,
        private PaymentRepository $payments,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function refund(CustomerOrder $order, Money $amount, string $reason, string $actorEmail): PaymentRefund
    {
        $reason = trim($reason);
        $actorEmail = mb_strtolower(trim($actorEmail));
        if ('' === $reason) {
            throw new \InvalidArgumentException('A refund requires a reason.');
        }
        if (false === filter_var($actorEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A refund requires the acting administrator.');
        }

        $refused = null;
        $refund = $this->entityManager->wrapInTransaction(function () use ($order, $amount, $reason, $actorEmail, &$refused): ?PaymentRefund {
            $payment = $this->payments->findOneForUpdate($order)
                ?? throw new PaymentNotFound(sprintf('Order %s has no payment to refund.', $order->orderNumber()));
            if (!$payment->state()->isRefundable()) {
                throw new \DomainException(sprintf('A %s payment cannot be refunded.', $payment->state()->value));
            }
            if ($amount->minorAmount() > $payment->refundableAmount()->minorAmount()) {
                throw new \DomainException('Refund exceeds the refundable amount.');
            }

            $gateway = $this->gateways->resolveOrFail($payment->providerKey());
            $providerReference = $this->capturedReference($payment);
            $outcome = $gateway->refund(new GatewayRefundInstruction(
                $providerReference,
                $amount,
                sprintf('refund-%s-%d', $payment->order()->orderNumber(), $payment->refundedAmount()->minorAmount()),
                $reason,
            ));
            if (RefundStatus::Completed !== $outcome->status()) {
                // No money moved. The refusal is recorded and the transaction commits normally,
                // so the attempt survives; the caller is told it failed only after that.
                $refused = $payment->recordRejectedRefund(
                    $amount,
                    $reason,
                    $outcome->failure() ?? SanitizedFailure::fromProvider('refund_failed', sprintf('The provider left the refund %s.', $outcome->status()->value), null),
                    \DateTimeImmutable::createFromInterface($this->clock->now()),
                );
                $this->payments->save($payment);
                $this->entityManager->flush();

                return null;
            }

            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $recorded = $payment->recordRefund($amount, $outcome->providerReference() ?? $providerReference, $reason, $now);
            // A partial refund leaves the order alone; only a full refund closes it. Either
            // way the money movement lives in the payment trail, not the order timeline.
            if ($payment->isFullyRefunded()) {
                $this->cancelOrder($order, $reason, $actorEmail);
            }
            $this->payments->save($payment);
            $this->entityManager->flush();

            return $recorded;
        });

        if (null !== $refused) {
            throw new RefundRefused($refused);
        }

        return $refund ?? throw new \LogicException('A refund request produced neither a refund nor a recorded refusal.');
    }

    private function capturedReference(Payment $payment): string
    {
        foreach (array_reverse($payment->attempts()) as $attempt) {
            if (null !== $attempt->providerReference()) {
                return $attempt->providerReference();
            }
        }

        throw new \DomainException('The captured payment has no provider reference to refund.');
    }

    private function cancelOrder(CustomerOrder $order, string $reason, string $actorEmail): void
    {
        if (OrderState::Cancelled === $order->state() || OrderState::Completed === $order->state()) {
            return;
        }
        $from = $order->state();
        $order->transitionTo(OrderState::Cancelled);
        $this->entityManager->persist(new OrderStatusChange(
            $order,
            $from,
            OrderState::Cancelled,
            sprintf('Fully refunded: %s', $reason),
            $actorEmail,
        ));
    }
}
