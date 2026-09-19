<?php

namespace App\Module\Catalog\Query;

use App\Repository\Catalog\CatalogReadRepository;

final readonly class CatalogQuery
{
    public function __construct(private CatalogReadRepository $repository)
    {
    }

    public function search(CatalogCriteria $criteria): CatalogPage
    {
        return $this->repository->search($criteria);
    }

    public function product(string $slug): ?CatalogProductDetail
    {
        return $this->repository->findPublishedProduct($slug);
    }

    /** @return list<CatalogOption> */
    public function categories(?int $limit = null): array
    {
        return $this->repository->publishedCategories($limit);
    }

    /** @return list<CatalogOption> */
    public function brands(?int $limit = null): array
    {
        return $this->repository->publishedBrands($limit);
    }

    public function category(string $slug): ?CatalogOption
    {
        return $this->repository->findPublishedCategory($slug);
    }

    public function brand(string $slug): ?CatalogOption
    {
        return $this->repository->findPublishedBrand($slug);
    }
}
