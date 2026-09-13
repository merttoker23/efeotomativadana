<?php

namespace App\Module\Inventory;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductInventory;

interface ProductInventoryRepositoryInterface
{
    public function findOneByProduct(Product $product): ?ProductInventory;

    public function findOneByProductForUpdate(Product $product): ?ProductInventory;

    public function save(ProductInventory $inventory): void;
}
