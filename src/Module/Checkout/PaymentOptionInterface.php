<?php

declare(strict_types=1);

namespace App\Module\Checkout;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.checkout.payment_option')]
interface PaymentOptionInterface
{
    public function key(): string;

    public function label(): string;

    public function available(): bool;

    public function productionReady(): bool;
}
