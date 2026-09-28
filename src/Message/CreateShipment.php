<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Ask a carrier to take one parcel.
 *
 * Carries only the local shipment id. The instruction is rebuilt from the sealed order at handling
 * time, so a message sitting in a queue for an hour still describes the order as it was placed
 * rather than as the product catalogue happens to look now.
 *
 * A duplicate of this message must be harmless: the handler asks the provider only while the
 * shipment is still waiting to be created, and the provider is given an idempotency key derived
 * from the order.
 */
final readonly class CreateShipment
{
    public function __construct(public int $shipmentId)
    {
        if ($shipmentId < 1) {
            throw new \InvalidArgumentException('Shipment message ID must be positive.');
        }
    }
}
