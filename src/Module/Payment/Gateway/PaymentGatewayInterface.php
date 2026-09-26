<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway;

use App\Module\Payment\SanitizedFailure;
use App\Shared\Money\Money;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The provider-neutral payment boundary.
 *
 * A concrete adapter (PHASE_15) implements this and nothing else in the application changes:
 * the checkout controller, the payment services and the order synchronisation all speak
 * these types. The contract is deliberately shaped around hosted redirect, hosted form,
 * tokenized collection and 3-D Secure style callbacks, and around a captured-amount echo, so
 * no vendor payload leaks into the domain.
 *
 * Implementations must not accept or persist raw card data.
 */
#[AutoconfigureTag('app.payment_gateway')]
interface PaymentGatewayInterface
{
    /** Stable provider key, matched against the `payment.provider` store setting. */
    public function key(): string;

    /** Customer-facing label for the admin payment screens. */
    public function label(): string;

    /**
     * Whether this adapter may settle real money in production. A test-only or fake adapter
     * must return false, which makes the registry refuse it outside dev/test.
     */
    public function productionReady(): bool;

    /** Start one payment attempt. Must be safe to call again with the same idempotency key. */
    public function initiate(GatewayInitiationInstruction $instruction): GatewayInitiationOutcome;

    /**
     * Verify a callback's authenticity over the raw request. Implementations return
     * {@see CallbackAuthentication::rejected()} whenever a signature, timestamp or replay
     * guard does not hold; they must never accept a callback on trust.
     */
    public function authenticateCallback(IncomingPaymentCallback $callback): CallbackAuthentication;

    /** Refund all or part of a captured payment. Must be idempotent per idempotency key. */
    public function refund(GatewayRefundInstruction $instruction): GatewayRefundOutcome;

    /**
     * Release an authorization that was never captured. Optional for gateways that capture
     * only; a provider with no separate void step returns null.
     */
    public function releaseAuthorization(GatewayRefundInstruction $instruction): ?GatewayRefundOutcome;
}
