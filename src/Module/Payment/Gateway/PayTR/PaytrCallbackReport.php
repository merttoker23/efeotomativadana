<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway\PayTR;

/**
 * One parsed PayTR payment notification.
 *
 * The values the signature is computed over are kept exactly as they were sent, because a
 * re-formatted amount or a re-cased status would produce a different hash. Everything else is
 * normalised for the domain.
 */
final readonly class PaytrCallbackReport
{
    public function __construct(
        private string $merchantOid,
        private string $status,
        private string $totalAmountAsSent,
        private int $totalAmountMinor,
        private ?int $paymentAmountMinor,
        private ?string $paymentType,
        private ?string $currency,
        private ?string $failedReasonCode,
        private ?string $failedReasonMessage,
        private bool $testMode,
        private string $hash,
    ) {
    }

    public function merchantOid(): string { return $this->merchantOid; }

    /** The status exactly as sent, because the hash is computed over it verbatim. */
    public function status(): string { return $this->status; }

    public function isSuccess(): bool { return 'success' === strtolower($this->status); }

    public function totalAmountAsSent(): string { return $this->totalAmountAsSent; }

    public function totalAmountMinor(): int { return $this->totalAmountMinor; }

    /**
     * The order amount the store asked for, in minor units.
     *
     * This — not {@see totalAmountMinor()} — is what may mark an order paid: when a customer
     * chooses an installment plan the total they hand to the bank is higher than the amount the
     * store is owed, and that difference never reaches the merchant.
     */
    public function paymentAmountMinor(): ?int { return $this->paymentAmountMinor; }

    public function paymentType(): ?string { return $this->paymentType; }

    public function currency(): ?string { return $this->currency; }

    public function failedReasonCode(): ?string { return $this->failedReasonCode; }

    public function failedReasonMessage(): ?string { return $this->failedReasonMessage; }

    public function isTestMode(): bool { return $this->testMode; }

    public function hash(): string { return $this->hash; }
}
