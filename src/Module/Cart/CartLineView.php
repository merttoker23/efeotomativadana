<?php

namespace App\Module\Cart;

use App\Shared\Money\Money;

final readonly class CartLineView
{
    public function __construct(
        public int $id,
        public int $productId,
        public string $name,
        public string $slug,
        public string $sku,
        public ?string $imagePath,
        public int $quantity,
        public int $availableQuantity,
        public ?Money $unitPrice,
        public ?Money $lineTotal,
        public bool $updatable,
        public bool $sellable,
    ) {
    }
}
