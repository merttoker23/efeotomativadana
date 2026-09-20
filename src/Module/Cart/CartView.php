<?php

namespace App\Module\Cart;

use App\Shared\Money\Money;

final readonly class CartView
{
    /** @param list<CartLineView> $items */
    public function __construct(
        public array $items,
        public int $itemCount,
        public ?Money $total,
    ) {
    }
}
