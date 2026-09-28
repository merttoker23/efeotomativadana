<?php

declare(strict_types=1);

namespace App\Module\Notification\Event;

use App\Entity\Commerce\ReturnRequest;
use App\Module\Returns\ReturnState;

/**
 * A return request changed state, or was opened.
 *
 * The note the customer is shown on the screen and the note they are mailed are the same string,
 * read from the aggregate, because a customer who is told one thing on a page and another in their
 * inbox has been told two different things.
 */
final readonly class ReturnStateChanged
{
    public function __construct(public ReturnRequest $return)
    {
    }

    public function returnNumber(): string
    {
        return $this->return->returnNumber();
    }

    public function orderNumber(): string
    {
        return $this->return->orderNumber();
    }

    public function recipient(): string
    {
        return $this->return->customerEmail();
    }

    public function state(): ReturnState
    {
        return $this->return->state();
    }

    /** The staff note for a decision, or the refund amount once money has moved. */
    public function detail(): string
    {
        return match ($this->return->state()) {
            // Minor units and the currency, not a formatted string: the money module owns the exact
            // representation and a second formatter here would be a second place to get it wrong.
            ReturnState::Refunded => null === $this->return->refundMinorAmount() ? '' : sprintf('%d %s', $this->return->refundMinorAmount()->minorAmount(), $this->return->refundMinorAmount()->currency()),
            default => $this->return->staffNote() ?? '',
        };
    }
}
