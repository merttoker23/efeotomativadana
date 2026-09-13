<?php

namespace App\Module\Inventory;

use App\Entity\Catalog\Product;

final readonly class InventoryQuery
{
    public function __construct(private ProductInventoryRepositoryInterface $inventory)
    {
    }

    public function forProduct(Product $product): InventoryView
    {
        $inventory = $this->inventory->findOneByProduct($product);
        if (null === $inventory) {
            return new InventoryView(0, false, false);
        }

        return new InventoryView(
            $inventory->quantity(),
            $inventory->availableForSale(),
            $inventory->isSellable(),
        );
    }
}
