<?php

declare(strict_types=1);

namespace App\Module\Order;

use App\Entity\Commerce\OrderItem;
use App\Shared\Money\Money;

/**
 * One order line as a customer-facing page shows it.
 *
 * Read from the order's own sealed snapshot, so a page opened in 2030 still shows the name and
 * price the customer actually paid rather than what the catalogue says now.
 */
final readonly class OrderItemLine
{
    private function __construct(
        private string $sku,
        private string $productName,
        private int $quantity,
        private Money $unitGross,
        private int $taxRateBasisPoints,
        private Money $lineGross,
        private ?string $imagePath,
    ) {
    }

    public static function fromItem(OrderItem $item, ?string $imagePath = null): self
    {
        return new self(
            $item->sku(),
            $item->productName(),
            $item->quantity(),
            $item->unitGross(),
            $item->taxRateBasisPoints(),
            $item->lineGross(),
            $imagePath,
        );
    }

    public function sku(): string { return $this->sku; }
    public function productName(): string { return $this->productName; }
    public function quantity(): int { return $this->quantity; }
    public function unitGross(): Money { return $this->unitGross; }
    public function taxRateBasisPoints(): int { return $this->taxRateBasisPoints; }
    public function lineGross(): Money { return $this->lineGross; }
    public function imagePath(): ?string { return $this->imagePath; }
}
