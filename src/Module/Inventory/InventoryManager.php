<?php

namespace App\Module\Inventory;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductInventory;
use App\Module\Inventory\Exception\InventoryNotFound;
use Doctrine\ORM\EntityManagerInterface;

final readonly class InventoryManager
{
    public function __construct(
        private ProductInventoryRepositoryInterface $inventory,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function upsert(
        Product $product,
        mixed $quantity,
        mixed $availableForSale = true,
    ): ProductInventory {
        $candidate = new ProductInventory($product, $quantity, $availableForSale);
        $inventory = $this->inventory->findOneByProduct($product);

        if (null === $inventory) {
            $inventory = $candidate;
        } else {
            $inventory->replace($candidate->quantity(), $candidate->availableForSale());
        }

        $this->inventory->save($inventory);
        $this->entityManager->flush();

        return $inventory;
    }

    public function adjust(Product $product, mixed $delta): ProductInventory
    {
        return $this->entityManager->wrapInTransaction(function () use ($product, $delta): ProductInventory {
            $inventory = $this->inventory->findOneByProductForUpdate($product);
            if (null === $inventory) {
                throw new InventoryNotFound('Inventory was not found for the requested product.');
            }

            $inventory->adjust($delta);
            $this->inventory->save($inventory);
            $this->entityManager->flush();

            return $inventory;
        });
    }
}
