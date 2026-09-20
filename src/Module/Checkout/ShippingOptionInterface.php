<?php

declare(strict_types=1);

namespace App\Module\Checkout;

use App\Shared\Money\Money;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.checkout.shipping_option')]
interface ShippingOptionInterface
{
    public function key(): string;

    public function label(): string;

    public function available(): bool;

    public function cost(string $currency): Money;
}
