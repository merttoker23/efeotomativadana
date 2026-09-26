<?php

declare(strict_types=1);

namespace App\Module\Payment;

final readonly class PaymentStateMachine
{
    public function canTransition(PaymentState $from, PaymentState $to): bool
    {
        return in_array($to, $this->allowedTargets($from), true);
    }

    public function assertTransition(PaymentState $from, PaymentState $to): PaymentState
    {
        if (!$this->canTransition($from, $to)) {
            throw new \DomainException(sprintf('Payment cannot transition from %s to %s.', $from->value, $to->value));
        }

        return $to;
    }

    /** @return list<PaymentState> */
    private function allowedTargets(PaymentState $from): array
    {
        return match ($from) {
            PaymentState::Pending => [PaymentState::RequiresAction, PaymentState::Succeeded, PaymentState::CapturedAmountMismatch, PaymentState::Failed, PaymentState::Cancelled],
            PaymentState::RequiresAction => [PaymentState::Succeeded, PaymentState::CapturedAmountMismatch, PaymentState::Failed, PaymentState::Cancelled],
            PaymentState::Succeeded => [PaymentState::PartiallyRefunded, PaymentState::Refunded],
            PaymentState::PartiallyRefunded => [PaymentState::Refunded],
            // A mismatched capture already holds the customer's money, so it can only be
            // given back, never retried or cancelled.
            PaymentState::CapturedAmountMismatch => [PaymentState::PartiallyRefunded, PaymentState::Refunded],
            PaymentState::Failed, PaymentState::Cancelled, PaymentState::Refunded => [],
        };
    }
}
