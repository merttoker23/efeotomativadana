<?php

declare(strict_types=1);

namespace App\Module\Notification\Event;

use App\Entity\Commerce\Shipment;

/**
 * A parcel left the building, or arrived.
 *
 * Carries the shipment so the tracking number is read from the aggregate at the moment the event
 * happened. A subscriber that re-read the parcel later could write a number the customer never saw.
 */
final readonly class ShipmentMoved
{
    public function __construct(public Shipment $shipment)
    {
    }

    public function orderNumber(): string
    {
        return $this->shipment->orderNumber();
    }

    public function recipient(): string
    {
        return $this->shipment->order()->customerEmail();
    }

    public function trackingNumber(): string
    {
        return $this->shipment->trackingNumber() ?? '';
    }
}
