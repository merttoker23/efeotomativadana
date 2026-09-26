<?php

declare(strict_types=1);

namespace App\Module\Checkout;

/**
 * A checkout payment option that is settled by an external payment gateway rather than by a
 * local manual verification. Only options of this type may own a {@see \App\Entity\Commerce\Payment}.
 */
interface GatewayPaymentOptionInterface extends PaymentOptionInterface
{
    /** Checkout option key shared by every gateway-backed option. */
    public const string CHECKOUT_KEY = 'gateway_checkout';

    /** The {@see \App\Module\Payment\PaymentGatewayInterface::key()} this option settles through. */
    public function gatewayKey(): string;
}
