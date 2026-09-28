<?php

declare(strict_types=1);

namespace App\Module\Shipping\Gateway;

/** One line of the order, as a carrier sees it: what it is and how many. */
final readonly class ShipmentItem
{
    private string $sku;

    private string $name;

    public function __construct(
        string $sku,
        string $name,
        private int $quantity,
    ) {
        $sku = trim($sku);
        $name = trim($name);
        if ('' === $sku) {
            throw new \InvalidArgumentException('A shipment item needs an SKU.');
        }
        if ('' === $name) {
            throw new \InvalidArgumentException('A shipment item needs a name.');
        }
        if ($quantity < 1) {
            throw new \InvalidArgumentException('A shipment item needs a positive whole quantity.');
        }

        $this->sku = $sku;
        $this->name = $name;
    }

    public function sku(): string { return $this->sku; }
    public function name(): string { return $this->name; }
    public function quantity(): int { return $this->quantity; }

    public function label(): string
    {
        return sprintf('%s %s x%d', $this->sku, $this->name, $this->quantity);
    }
}
