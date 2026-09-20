<?php

declare(strict_types=1);

namespace App\Module\Checkout;

use App\Entity\Customer\CustomerAddress;

final readonly class CheckoutView
{
    /**
     * @param list<CustomerAddress>        $addresses
     * @param list<ShippingOptionInterface> $shippingOptions
     * @param list<PaymentOptionInterface>  $paymentOptions
     */
    public function __construct(
        public array $addresses,
        public array $shippingOptions,
        public array $paymentOptions,
    ) {
    }
}
