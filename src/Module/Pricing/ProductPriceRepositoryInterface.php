<?php

namespace App\Module\Pricing;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductPrice;

interface ProductPriceRepositoryInterface
{
    public function findOneByProduct(Product $product): ?ProductPrice;

    public function save(ProductPrice $price): void;
}
