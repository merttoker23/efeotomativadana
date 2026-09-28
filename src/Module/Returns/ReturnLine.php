<?php

declare(strict_types=1);

namespace App\Module\Returns;

use App\Entity\Commerce\OrderItem;

/**
 * One line of a return request, as the customer submitted it.
 *
 * Only the order line, the quantity and the reason. Price, tax rate, SKU and name are read from
 * the order item when the request is built, so a hand-crafted POST cannot quote a return at a
 * price the customer chose.
 */
final class ReturnLine
{
    private string $reason;

    public function __construct(
        private readonly OrderItem $orderItem,
        private readonly int $quantity,
        string $reason,
    ) {
        $reason = trim($reason);
        if ($quantity < 1) {
            throw new \InvalidArgumentException('A return line needs a quantity of at least one.');
        }
        if ('' === $reason || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('A return line requires a reason.');
        }
        $this->reason = $reason;
    }

    public function orderItem(): OrderItem
    {
        return $this->orderItem;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
