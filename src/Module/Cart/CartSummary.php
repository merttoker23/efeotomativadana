<?php

namespace App\Module\Cart;

use App\Shared\Money\Money;

final readonly class CartSummary
{
    public function __construct(
        public int $itemCount,
        public ?Money $total,
    ) {
    }
}
