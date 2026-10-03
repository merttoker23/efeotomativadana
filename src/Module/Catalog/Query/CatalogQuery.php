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

    /** @return list<CatalogProductView> */
    public function similarProducts(int $productId): array
    {
        return $this->repository->similarProducts($productId);
    }

    /**
     * @param list<string> $slugs
     *
     * @return list<CatalogProductView>
     */
    public function products(array $slugs): array
    {
        return $this->repository->findPublishedProductViews($slugs);
    }

    /**
     * Price, stock and lead image for a known set of products, keyed by product id.
     *
     * One query for the whole set, so a cart, wishlist or comparison pays for its contents once
     * rather than four times per line.
     *
     * @param list<int> $productIds
     *
     * @return array<int, CatalogProductView>
     */
    public function snapshots(array $productIds): array
    {
        return $this->repository->productSnapshots($productIds);
    }

    /**
     * Named categories or brands, resolved together.
     *
     * @param list<string> $slugs
     *
     * @return list<CatalogOption>
     */
    public function optionsBySlug(string $kind, array $slugs): array
    {
        return $this->repository->optionsBySlug($kind, $slugs);
    }

    /** @return list<CatalogOption> */
    public function categoriesByPopularity(int $limit): array
    {
        return $this->repository->categoriesByPopularity($limit);
    }

    /** @return list<CatalogOption> */
    public function brandsByPopularity(int $limit): array
    {
        return $this->repository->brandsByPopularity($limit);
    }

    public function brandPage(int $page, int $perPage): \App\Shared\PagedResult
    {
        return $this->repository->brandPage($page, $perPage);
    }

    public function categoryPage(int $page, int $perPage): \App\Shared\PagedResult
    {
        return $this->repository->categoryPage($page, $perPage);
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
