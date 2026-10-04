<?php

declare(strict_types=1);

namespace App\Module\Checkout;

use App\Module\Cart\CartView;
use App\Shared\Money\Money;

/** Presentation only; LocalStandardShippingOption remains the shipping cost authority. */
final readonly class FreeShippingProgress
{
    private function __construct(
        public Money $remaining,
        public int $percentage,
    ) {
    }

    public static function forCart(CartView $cart, int $threshold): ?self
    {
        if ([] === $cart->items || null === $cart->total || $threshold <= $cart->total->minorAmount()) {
            return null;
        }

        return new self(
            Money::ofMinor($threshold - $cart->total->minorAmount(), $cart->total->currency()),
            min(99, (int) floor($cart->total->minorAmount() / $threshold * 100)),
        );
    }
}
