<?php

declare(strict_types=1);

namespace App\Module\Payment;

use App\Module\Payment\Gateway\CallbackAuthentication;
use App\Module\Payment\Gateway\GatewayInitiationInstruction;
use App\Module\Payment\Gateway\GatewayInitiationOutcome;
use App\Module\Payment\Gateway\GatewayRefundInstruction;
use App\Module\Payment\Gateway\GatewayRefundOutcome;
use App\Module\Payment\Gateway\IncomingPaymentCallback;
use App\Module\Payment\Gateway\PaymentGatewayInterface;
use App\Shared\Money\Money;

/**
 * A deterministic, in-memory payment gateway used only by the test environment.
 *
 * It is registered through `when@test` in `config/services.yaml`, reports itself as not
 * production ready, and is refused by {@see PaymentGatewayRegistry} in the `prod`
 * environment — so it cannot quietly take real money even if it is left wired up.
 *
 * Every outcome is queued explicitly. Running out of queued outcomes throws instead of
 * inventing a success, so a test can never pass because the fake defaulted to "paid".
 */
final class FakePaymentGateway implements PaymentGatewayInterface
{
    private const string SIGNATURE_HEADER = 'X-Fake-Signature';
    private const string SIGNATURE_VALUE = 'valid';

    /** @var list<GatewayInitiationOutcome> */
    private array $initiations = [];

    /** @var list<GatewayRefundOutcome> */
    private array $refunds = [];

    /** @var list<GatewayRefundOutcome> */
    private array $releases = [];

    /** @var array<string, GatewayInitiationOutcome> return token => issued outcome */
    private array $issued = [];

    /** @var array<string, string> reference => currency that reference was charged in */
    private array $issuedCurrency = [];

    /** @var array<string, bool> references already judged unknown once */
    private array $knownReferences = [];

    /** @var array<string, string> return token => currency that attempt was asked to charge */
    private array $attemptCurrency = [];

    public function key(): string { return 'fake'; }

    public function label(): string { return 'Test ödeme ağ geçidi'; }

    public function productionReady(): bool { return false; }

    public function initiate(GatewayInitiationInstruction $instruction): GatewayInitiationOutcome
    {
        // The return token is the store's per-attempt identity, so it is the only safe key
        // for an idempotency memo: a sequence number repeats across different orders.
        if (isset($this->issued[$instruction->returnToken()])) {
            return $this->issued[$instruction->returnToken()];
        }

        $outcome = array_shift($this->initiations) ?? throw new \OutOfBoundsException('The fake payment gateway has no queued initiation outcome.');
        $this->issued[$instruction->returnToken()] = $outcome;
        if (null !== $outcome->providerReference()) {
            $this->issuedCurrency[$outcome->providerReference()] = $instruction->amount()->currency();
        }
        // Recorded per attempt, because a gateway that issues no reference up front still knows
        // which currency it was asked to charge.
        $this->attemptCurrency[$instruction->returnToken()] = $instruction->amount()->currency();

        return $outcome;
    }

    public function authenticateCallback(IncomingPaymentCallback $callback): CallbackAuthentication
    {
        if (self::SIGNATURE_VALUE !== $callback->header(self::SIGNATURE_HEADER)) {
            return CallbackAuthentication::rejected('signature_mismatch');
        }

        parse_str($callback->rawBody(), $parsed);
        $reference = is_string($parsed['ref'] ?? null) ? trim($parsed['ref']) : '';
        if ('' === $reference) {
            return CallbackAuthentication::rejected('unknown_reference');
        }
        if (!$this->knowsReference($reference, $callback->returnToken())) {
            return CallbackAuthentication::rejected('unknown_reference');
        }

        $outcome = is_string($parsed['outcome'] ?? null) ? trim($parsed['outcome']) : '';
        if (!in_array($outcome, ['succeeded', 'failed', 'cancelled'], true)) {
            return CallbackAuthentication::rejected('unknown_outcome');
        }
        $amount = $parsed['amount'] ?? null;
        if (!is_string($amount) || 1 !== preg_match('/^\d{1,18}$/', $amount)) {
            return CallbackAuthentication::rejected('malformed_amount');
        }
        $currency = is_string($parsed['currency'] ?? null) ? strtoupper(trim($parsed['currency'])) : '';
        try {
            // A capture of nothing is never a real capture, so it is refused here rather
            // than being allowed to reach the amount-mismatch guard downstream.
            $captured = 'succeeded' === $outcome ? Money::ofMinor((int) $amount, $currency) : null;
        } catch (\InvalidArgumentException) {
            return CallbackAuthentication::rejected('unknown_currency');
        }
        if (null !== $captured && $captured->isZero()) {
            return CallbackAuthentication::rejected('empty_capture');
        }
        if (null !== $captured && $captured->currency() !== ($this->issuedCurrency[$reference] ?? 'TRY')) {
            return CallbackAuthentication::rejected('currency_mismatch');
        }

        return CallbackAuthentication::authentic($reference, $outcome, $captured);
    }

    /**
     * A reference is known if the provider issued it, or if this exact reference arrives for the
     * first time on an attempt that is still waiting for the provider to decide. Some real
     * gateways only assign a reference when the money moves; anything else is a forgery.
     */
    private function knowsReference(string $reference, string $returnToken): bool
    {
        foreach ($this->issued as $token => $outcome) {
            if ($outcome->providerReference() === $reference) {
                return true;
            }
            if ($token !== $returnToken) {
                continue;
            }
            if (!$outcome->isAwaitingCallback() || isset($this->knownReferences[$reference])) {
                return false;
            }
            // Bind the brand-new reference to this attempt, so a later callback for it is
            // checked against the right currency rather than whichever attempt came last.
            $this->issuedCurrency[$reference] = $this->attemptCurrency[$token] ?? 'TRY';
            $this->knownReferences[$reference] = true;

            return true;
        }

        return false;
    }

    public function refund(GatewayRefundInstruction $instruction): GatewayRefundOutcome
    {
        return array_shift($this->refunds) ?? throw new \OutOfBoundsException('The fake payment gateway has no queued refund outcome.');
    }

    /**
     * A separate queue from refunds: a gateway that cannot void anything returns null, and a
     * queued refund outcome must not be silently consumed as a void.
     */
    public function releaseAuthorization(GatewayRefundInstruction $instruction): ?GatewayRefundOutcome
    {
        if ([] === $this->releases) {
            return null;
        }

        return array_shift($this->releases);
    }

    public function queueInitiation(GatewayInitiationOutcome $outcome): void
    {
        $this->initiations[] = $outcome;
    }

    public function queueRefund(GatewayRefundOutcome $outcome): void
    {
        $this->refunds[] = $outcome;
    }

    public function queueRelease(GatewayRefundOutcome $outcome): void
    {
        $this->releases[] = $outcome;
    }
}
