<?php

declare(strict_types=1);

namespace App\Module\Checkout;

use App\Shared\Money\Money;
use App\Module\Settings\StoreConfiguration;

final readonly class LocalStandardShippingOption implements ShippingOptionInterface
{
    public function __construct(private StoreConfiguration $settings) {}

    public function key(): string { return 'local_standard'; }
    public function label(): string { return 'Yerel standart teslimat'; }
    public function available(): bool { return true; }
    public function cost(Money $subtotal): Money
    {
        $fee = $subtotal->minorAmount() >= $this->settings->freeShippingThreshold() ? 0 : $this->settings->shippingFee();

        return Money::ofMinor($fee, $subtotal->currency());
    }
}
