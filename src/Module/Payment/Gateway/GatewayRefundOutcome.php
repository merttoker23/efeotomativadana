<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway;

use App\Module\Payment\SanitizedFailure;

final readonly class GatewayRefundOutcome
{
    private function __construct(
        private RefundStatus $status,
        private ?string $providerReference,
        private ?SanitizedFailure $failure,
    ) {
    }

    public static function pending(?string $providerReference = null): self
    {
        return new self(RefundStatus::Pending, self::reference($providerReference), null);
    }

    public static function completed(string $providerReference): self
    {
        return new self(RefundStatus::Completed, self::reference($providerReference), null);
    }

    public static function failed(SanitizedFailure $failure): self
    {
        return new self(RefundStatus::Failed, null, $failure);
    }

    public function status(): RefundStatus { return $this->status; }
    public function providerReference(): ?string { return $this->providerReference; }
    public function failure(): ?SanitizedFailure { return $this->failure; }

    private static function reference(?string $providerReference): ?string
    {
        if (null === $providerReference) {
            return null;
        }
        $providerReference = trim($providerReference);

        return '' === $providerReference ? null : $providerReference;
    }
}
