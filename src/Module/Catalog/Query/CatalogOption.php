<?php

namespace App\Module\Catalog\Query;

final readonly class CatalogOption
{
    public function __construct(
        public string $name,
        public string $slug,
        public int $productCount,
    ) {
    }
}
