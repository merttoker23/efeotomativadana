<?php

declare(strict_types=1);

namespace App\Module\Shipping\Gateway;

use App\Module\Shipping\ShipmentText;

/** Addresses one parcel at its carrier for a status read. */
final readonly class ShipmentStatusRequest
{
    private string $providerKey;

    private string $providerReference;

    public function __construct(string $providerKey, string $providerReference)
    {
        $providerKey = ShipmentText::code($providerKey);
        $providerReference = ShipmentText::code($providerReference);
        if (null === $providerKey) {
            throw new \InvalidArgumentException('Shipment status provider key is required.');
        }
        if (null === $providerReference) {
            throw new \InvalidArgumentException('Shipment status provider reference is required.');
        }

        $this->providerKey = $providerKey;
        $this->providerReference = $providerReference;
    }

    public function providerKey(): string { return $this->providerKey; }
    public function providerReference(): string { return $this->providerReference; }
}
