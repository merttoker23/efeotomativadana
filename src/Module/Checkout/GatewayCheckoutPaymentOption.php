<?php

declare(strict_types=1);

namespace App\Module\Checkout;

/**
 * The single checkout payment option that routes a customer into gateway orchestration.
 *
 * Which concrete gateway is behind it is decided by the `payment.provider` store setting, so
 * the checkout template and the order snapshot stay stable when PHASE_15 swaps the adapter.
 */
final readonly class GatewayCheckoutPaymentOption implements GatewayPaymentOptionInterface
{
    public function key(): string { return self::CHECKOUT_KEY; }

    public function label(): string { return 'Kredi kartı ile ödeme'; }

    public function available(): bool { return true; }

    public function productionReady(): bool { return true; }

    public function gatewayKey(): string { return 'gateway_checkout'; }
}
