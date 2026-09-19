<?php

namespace App\Module\Catalog\Query;

use App\Shared\Money\Money;

final readonly class CatalogProductView
{
    public function __construct(
        public int $id,
        public string $sku,
        public string $name,
        public string $slug,
        public ?string $brandName,
        public ?string $brandSlug,
        public ?string $imagePath,
        public ?string $imageAlt,
        public ?Money $basePrice,
        public ?Money $sellPrice,
        public bool $onSale,
        public int $quantity,
        public bool $sellable,
    ) {
    }
}
