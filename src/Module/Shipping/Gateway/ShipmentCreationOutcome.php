<?php

declare(strict_types=1);

namespace App\Module\Shipping\Gateway;

use App\Module\Payment\SanitizedFailure;
use App\Module\Shipping\ShipmentText;

/**
 * What a carrier said when it was asked to take a parcel.
 *
 * A reference is mandatory on an accepted creation. `cancel()` and `status()` have no other way
 * to address a parcel, so a carrier that accepted one without a reference could not be managed
 * afterwards; an adapter with only a barcode passes the barcode.
 */
final readonly class ShipmentCreationOutcome
{
    private function __construct(
        private string $providerReference,
        private ?string $trackingNumber,
        private ?SanitizedFailure $failure,
    ) {
    }

    public static function accepted(string $providerReference, ?string $trackingNumber = null): self
    {
        $providerReference = ShipmentText::code($providerReference);
        if (null === $providerReference) {
            throw new \InvalidArgumentException('An accepted shipment must carry a provider reference.');
        }

        return new self($providerReference, ShipmentText::tracking($trackingNumber), null);
    }

    public static function refused(SanitizedFailure $failure): self
    {
        return new self('', null, $failure);
    }

    public function isAccepted(): bool
    {
        return null === $this->failure;
    }

    public function providerReference(): ?string
    {
        return '' === $this->providerReference ? null : $this->providerReference;
    }

    public function trackingNumber(): ?string
    {
        return $this->trackingNumber;
    }

    public function failure(): ?SanitizedFailure
    {
        return $this->failure;
    }
}
