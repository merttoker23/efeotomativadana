<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway;

use App\Shared\Money\Money;

/** One refund request handed to a gateway. Never carries card data. */
final readonly class GatewayRefundInstruction
{
    public function __construct(
        private string $providerReference,
        private Money $amount,
        private string $idempotencyKey,
        private string $reason,
    ) {
        if ('' === trim($this->providerReference)) {
            throw new \InvalidArgumentException('Refund requires the original provider reference.');
        }
        if ($this->amount->isZero()) {
            throw new \InvalidArgumentException('Refund amount must be greater than zero.');
        }
        if ('' === trim($this->idempotencyKey)) {
            throw new \InvalidArgumentException('Refund requires an idempotency key.');
        }
    }

    public function providerReference(): string { return $this->providerReference; }
    public function amount(): Money { return $this->amount; }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
    public function reason(): string { return $this->reason; }
}
