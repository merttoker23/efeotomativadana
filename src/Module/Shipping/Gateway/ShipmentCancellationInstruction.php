<?php

declare(strict_types=1);

namespace App\Module\Shipping\Gateway;

use App\Module\Shipping\ShipmentText;

/** Everything a carrier needs to recall one parcel that has not left the building. */
final readonly class ShipmentCancellationInstruction
{
    private string $providerKey;

    private string $providerReference;

    private string $idempotencyKey;

    private string $reason;

    public function __construct(
        string $providerKey,
        string $providerReference,
        string $idempotencyKey,
        string $reason,
    ) {
        $providerKey = ShipmentText::code($providerKey);
        $providerReference = ShipmentText::code($providerReference);
        $idempotencyKey = ShipmentText::code($idempotencyKey);
        $reason = ShipmentText::description($reason);
        if (null === $providerKey) {
            throw new \InvalidArgumentException('Shipment cancellation provider key is required.');
        }
        if (null === $providerReference) {
            throw new \InvalidArgumentException('Shipment cancellation provider reference is required.');
        }
        if (null === $idempotencyKey) {
            throw new \InvalidArgumentException('Shipment cancellation idempotency key is required.');
        }
        // A cancellation is a decision an operator makes and an auditor reads back. Without a
        // reason there is nothing to audit.
        if (null === $reason) {
            throw new \InvalidArgumentException('A shipment cancellation requires a reason.');
        }

        $this->providerKey = $providerKey;
        $this->providerReference = $providerReference;
        $this->idempotencyKey = $idempotencyKey;
        $this->reason = $reason;
    }

    public function providerKey(): string { return $this->providerKey; }
    public function providerReference(): string { return $this->providerReference; }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
    public function reason(): string { return $this->reason; }
}
