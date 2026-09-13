<?php

namespace App\Module\Inventory;

final readonly class InventoryView
{
    private int $quantity;

    private bool $availableForSale;

    private bool $sellable;

    public function __construct(mixed $quantity, mixed $availableForSale, mixed $sellable)
    {
        if (!is_int($quantity) || $quantity < 0) {
            throw new \InvalidArgumentException('Inventory view quantity must be a non-negative integer.');
        }

        if (!is_bool($availableForSale) || !is_bool($sellable)) {
            throw new \InvalidArgumentException('Inventory view availability values must be booleans.');
        }

        if ($sellable !== ($availableForSale && $quantity > 0)) {
            throw new \InvalidArgumentException('Inventory view sellability is inconsistent with quantity and availability.');
        }

        $this->quantity = $quantity;
        $this->availableForSale = $availableForSale;
        $this->sellable = $sellable;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function availableForSale(): bool
    {
        return $this->availableForSale;
    }

    public function sellable(): bool
    {
        return $this->sellable;
    }
}
