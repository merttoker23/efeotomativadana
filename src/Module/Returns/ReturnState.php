<?php

declare(strict_types=1);

namespace App\Module\Returns;

/**
 * Return lifecycle states, independent from {@see \App\Module\Order\OrderState}.
 *
 * A return is a request the customer makes about goods they already own. It is never conflated
 * with the order: an order that is being returned is still a real order with real money against it,
 * and the money only moves when {@see \App\Module\Payment\PaymentRefundService} says so. Collapsing
 * the two would make "we agreed to take this back" indistinguishable from "we have your money
 * back", which is the exact claim a customer would be misled by.
 */
enum ReturnState: string
{
    /** The customer asked; the store has not answered yet. */
    case Requested = 'requested';
    /** The store agreed to take the goods back. The goods have not arrived. */
    case Approved = 'approved';
    /** The goods are back with the store. The money has not moved. */
    case Received = 'received';
    /** A refund was issued against this return. */
    case Refunded = 'refunded';
    /** The store refused. Terminal, and the customer is told why. */
    case Rejected = 'rejected';
    /** The customer took it back before the store answered. Terminal. */
    case Withdrawn = 'withdrawn';

    public function isTerminal(): bool
    {
        return match ($this) {
            ReturnState::Refunded, ReturnState::Rejected, ReturnState::Withdrawn => true,
            ReturnState::Requested, ReturnState::Approved, ReturnState::Received => false,
        };
    }

    /** Only while the store has not yet answered. */
    public function isOpen(): bool
    {
        return ReturnState::Requested === $this;
    }

    /** The goods are in and the money has not been returned yet. */
    public function awaitsRefund(): bool
    {
        return ReturnState::Received === $this;
    }
}
