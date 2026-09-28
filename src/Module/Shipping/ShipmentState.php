<?php

declare(strict_types=1);

namespace App\Module\Shipping;

/**
 * Shipment lifecycle states, deliberately independent from {@see \App\Module\Order\OrderState}.
 *
 * The two are related but never conflated: an order is confirmed when the store accepts the
 * customer's money, while the shipment for that order may still be preparing, may fail at the
 * carrier, or may be cancelled while the order stands. Collapsing them would make it impossible
 * to record "the carrier lost this parcel" without also lying about the order.
 */
enum ShipmentState: string
{
    /** The local record exists; no provider has been engaged yet. */
    case Pending = 'pending';
    /** The parcel is with the carrier (or in the store's own van) and on its way. */
    case Ready = 'ready';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    /** Creation or a later provider call failed. Not terminal: the same shipment may be retried. */
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            ShipmentState::Delivered, ShipmentState::Cancelled => true,
            ShipmentState::Pending, ShipmentState::Ready, ShipmentState::InTransit, ShipmentState::Failed => false,
        };
    }

    /**
     * Whether an operator may cancel this shipment by hand.
     *
     * Once the parcel is on the road, recalling it is a return with its own reason and its own
     * audit trail, not a cancellation. Keeping that out of this state is what stops an admin
     * button from pretending a courier was stopped.
     */
    public function isCancellable(): bool
    {
        return match ($this) {
            ShipmentState::Pending, ShipmentState::Ready, ShipmentState::Failed => true,
            ShipmentState::InTransit, ShipmentState::Delivered, ShipmentState::Cancelled => false,
        };
    }

    public function isFulfilled(): bool
    {
        return ShipmentState::Delivered === $this;
    }
}
