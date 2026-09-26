<?php

declare(strict_types=1);

namespace App\Module\Payment;

/**
 * Payment lifecycle states. Deliberately independent from {@see \App\Module\Order\OrderState}:
 * a payment can fail or be cancelled while its order stays in place, and an order can be
 * cancelled while a captured payment still needs to be refunded.
 */
enum PaymentState: string
{
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Succeeded = 'succeeded';
    /**
     * The provider captured something, but not the amount this order expects. The money is
     * real and the payment stays refundable; only the order is not confirmed. Resolving the
     * difference is a reconciliation task, so this state must never pretend the capture
     * did not happen.
     */
    case CapturedAmountMismatch = 'captured_amount_mismatch';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    public function isSuccessful(): bool
    {
        return PaymentState::Succeeded === $this;
    }

    /**
     * Whether the provider captured money for this payment, which stays true while only
     * part of that money has been given back — and stays true for a mismatched capture,
     * because the money left the customer either way.
     */
    public function hasCapturedFunds(): bool
    {
        return match ($this) {
            PaymentState::Succeeded, PaymentState::PartiallyRefunded, PaymentState::CapturedAmountMismatch => true,
            PaymentState::Pending, PaymentState::RequiresAction, PaymentState::Failed, PaymentState::Cancelled, PaymentState::Refunded => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            PaymentState::Failed, PaymentState::Cancelled, PaymentState::Refunded, PaymentState::CapturedAmountMismatch => true,
            PaymentState::Pending, PaymentState::RequiresAction, PaymentState::Succeeded, PaymentState::PartiallyRefunded => false,
        };
    }

    public function canBeRetried(): bool
    {
        return match ($this) {
            PaymentState::Pending, PaymentState::RequiresAction, PaymentState::Failed => true,
            PaymentState::Succeeded, PaymentState::CapturedAmountMismatch, PaymentState::Cancelled, PaymentState::PartiallyRefunded, PaymentState::Refunded => false,
        };
    }

    public function isRefundable(): bool
    {
        return match ($this) {
            PaymentState::Succeeded, PaymentState::PartiallyRefunded, PaymentState::CapturedAmountMismatch => true,
            PaymentState::Pending, PaymentState::RequiresAction, PaymentState::Failed, PaymentState::Cancelled, PaymentState::Refunded => false,
        };
    }

    /**
     * Whether a provider callback still has a decision to make. Any other state means the
     * provider's report was already applied, so a repeat is a replay and must change nothing.
     */
    public function awaitsCallbackDecision(): bool
    {
        return PaymentState::Pending === $this || PaymentState::RequiresAction === $this;
    }
}
