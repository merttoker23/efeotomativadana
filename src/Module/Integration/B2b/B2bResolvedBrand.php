<?php

namespace App\Module\Integration\B2b;

use App\Entity\Catalog\Brand;

final readonly class B2bResolvedBrand
{
    public function __construct(
        public Brand $brand,
        public bool $created = false,
    ) {
    }
}
