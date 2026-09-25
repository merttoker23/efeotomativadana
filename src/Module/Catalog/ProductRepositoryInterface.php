<?php

namespace App\Module\Catalog;

use App\Entity\Catalog\Product;

interface ProductRepositoryInterface
{
    public function findOneBySku(string $sku): ?Product;

    /**
     * @param list<string> $skus
     * @return list<Product>
     */
    public function findBySkus(array $skus): array;

    /**
     * @param list<int> $ids
     * @return list<Product>
     */
    public function findByIds(array $ids): array;

    public function findOneBySlug(string $slug): ?Product;

    public function findOnePublishedBySlug(string $slug): ?Product;

    /** @return list<Product> */
    public function findByIdentifier(ProductIdentifierType $type, string $code): array;

    public function save(Product $product): void;
}
