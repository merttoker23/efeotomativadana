<?php

namespace App\Module\Catalog;

use App\Entity\Catalog\Brand;

interface BrandRepositoryInterface
{
    public function findOneBySlug(string $slug): ?Brand;

    public function save(Brand $brand): void;
}
