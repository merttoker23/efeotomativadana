<?php

declare(strict_types=1);

namespace App\Module\Returns;

use App\Entity\Commerce\OrderItem;

/**
 * What the return form holds while a customer is filling it in.
 *
 * The `lines` collection is built by the controller from the order the customer actually owns, with
 * the quantity field capped at what is still free to return. A posted `order_item_id` is never read:
 * the form names the order line by its position in *this* order, so a hand-crafted request cannot
 * introduce a line from somewhere else.
 */
final class ReturnRequestData
{
    public string $customerReason = '';

    /** @var list<ReturnLineData> */
    public array $lines = [];

    /**
     * @param list<array{line: ReturnLineData, item: OrderItem, max: int}> $lines
     */
    public function __construct(array $lines)
    {
        foreach ($lines as $line) {
            $this->lines[] = $line['line'];
        }
    }

    /**
     * The submitted lines that name at least one unit, with the reason filled in.
     *
     * Keyed by their position in the order, because that position is what addresses an order line —
     * a line the order does not have cannot be named at all.
     *
     * @return array<int, ReturnLineData>
     */
    public function filledLines(): array
    {
        $filled = [];
        foreach ($this->lines as $index => $data) {
            if ($data->quantity > 0 && '' !== trim($data->reason)) {
                $filled[$index] = $data;
            }
        }

        return $filled;
    }
}
