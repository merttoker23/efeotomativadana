<?php

namespace App\Module\Integration\B2b;

use App\Entity\Catalog\Category;

final readonly class B2bResolvedCategory
{
    public function __construct(
        public Category $category,
        public int $createdCount = 0,
        public int $reusedCount = 0,
    ) {
    }
}
