<?php

declare(strict_types=1);

namespace App\Module\Shipping\Gateway;

use App\Module\Payment\SanitizedFailure;

/** What a carrier said when it was asked to recall one parcel. */
final readonly class ShipmentCancellationOutcome
{
    private function __construct(private ?SanitizedFailure $failure)
    {
    }

    public static function cancelled(): self
    {
        return new self(null);
    }

    public static function refused(SanitizedFailure $failure): self
    {
        return new self($failure);
    }

    public function isCancelled(): bool
    {
        return null === $this->failure;
    }

    public function failure(): ?SanitizedFailure
    {
        return $this->failure;
    }
}
