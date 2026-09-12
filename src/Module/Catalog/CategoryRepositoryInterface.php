<?php

namespace App\Module\Catalog;

use App\Entity\Catalog\Category;

interface CategoryRepositoryInterface
{
    public function findOneBySlug(string $slug): ?Category;

    public function save(Category $category): void;
}
