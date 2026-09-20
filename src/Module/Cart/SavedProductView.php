<?php

namespace App\Module\Cart;

use App\Shared\Money\Money;

final readonly class SavedProductView
{
    /** @param array<string, string> $attributes */
    public function __construct(
        public int $selectionId,
        public int $productId,
        public string $name,
        public string $slug,
        public string $sku,
        public ?string $imagePath,
        public ?Money $price,
        public bool $sellable,
        public array $attributes = [],
    ) {
    }
}
