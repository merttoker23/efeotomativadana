<?php

namespace App\Module\Catalog\Query;

use App\Shared\Money\Money;

final readonly class CatalogProductDetail
{
    /**
     * @param list<array{path: string, alt: string}>         $images
     * @param list<array{type: string, label: string, code: string}> $identifiers
     * @param list<array{key: string, label: string, value: string}> $attributes
     * @param list<CatalogOption>                            $categories
     */
    public function __construct(
        public int $id,
        public string $sku,
        public string $name,
        public string $slug,
        public ?string $description,
        public ?string $brandName,
        public ?string $brandSlug,
        public array $images,
        public array $identifiers,
        public array $attributes,
        public array $categories,
        public ?Money $basePrice,
        public ?Money $sellPrice,
        public bool $onSale,
        public int $quantity,
        public bool $sellable,
    ) {
    }
}
