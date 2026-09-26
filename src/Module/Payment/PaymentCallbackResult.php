<?php

declare(strict_types=1);

namespace App\Module\Payment;

/**
 * The single answer a callback entry point needs: was the provider's report accepted, and if
 * not, why. Nothing about a provider leaks out of here.
 */
final readonly class PaymentCallbackResult
{
    private function __construct(
        private bool $accepted,
        private bool $replayed,
        private string $reason,
        private ?PaymentState $state,
    ) {
    }

    public static function applied(PaymentState $state): self
    {
        return new self(true, false, 'applied', $state);
    }

    public static function replay(PaymentState $state): self
    {
        return new self(false, true, 'replayed', $state);
    }

    public static function rejected(string $reason, ?PaymentState $state = null): self
    {
        return new self(false, false, $reason, $state);
    }

    public function accepted(): bool { return $this->accepted; }

    /** True when the same provider report had already been applied. */
    public function replayed(): bool { return $this->replayed; }

    public function reason(): string { return $this->reason; }

    public function state(): ?PaymentState { return $this->state; }
}
