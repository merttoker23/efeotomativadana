<?php

declare(strict_types=1);

namespace App\Module\Checkout;

use App\Shared\Money\Money;

final readonly class LocalStandardShippingOption implements ShippingOptionInterface
{
    public function key(): string { return 'local_standard'; }
    public function label(): string { return 'Yerel standart teslimat'; }
    public function available(): bool { return true; }
    public function cost(string $currency): Money { return Money::ofMinor(0, $currency); }
}
