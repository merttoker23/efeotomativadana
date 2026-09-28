<?php

declare(strict_types=1);

namespace App\Module\Shipping\Gateway;

use App\Module\Shipping\ShipmentState;
use App\Module\Shipping\ShipmentText;

/**
 * Where a carrier says a parcel is, translated into this store's own vocabulary.
 *
 * An unmapped status is reported as such instead of being guessed. Guessing would move a
 * parcel on no evidence at all, and a carrier that invents a new status code is an ordinary
 * event rather than an error.
 */
final readonly class ShipmentStatusReport
{
    private function __construct(
        private ?ShipmentState $state,
        private ?string $trackingNumber,
        private ?string $providerStatus,
    ) {
    }

    public static function reporting(ShipmentState $state, ?string $trackingNumber, ?string $providerStatus): self
    {
        $providerStatus = ShipmentText::status($providerStatus) ?? $state->value;

        return new self($state, ShipmentText::tracking($trackingNumber), $providerStatus);
    }

    public static function unrecognised(?string $providerStatus): self
    {
        return new self(null, null, ShipmentText::status($providerStatus) ?? 'unrecognised');
    }

    public function isRecognised(): bool
    {
        return null !== $this->state;
    }

    public function state(): ?ShipmentState
    {
        return $this->state;
    }

    public function trackingNumber(): ?string
    {
        return $this->trackingNumber;
    }

    /** The carrier's own wording, kept for the audit trail so a mapping can be explained. */
    public function providerStatus(): string
    {
        return $this->providerStatus ?? '';
    }
}
