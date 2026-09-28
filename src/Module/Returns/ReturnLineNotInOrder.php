<?php

declare(strict_types=1);

namespace App\Module\Returns;

/**
 * A submitted line names an order item that is not part of the order being returned.
 *
 * Only reachable by posting an `order_item_id` by hand, and it is a separate exception because it
 * is a different mistake: the line is not part of *this* order, which is a boundary, not a ceiling.
 */
final class ReturnLineNotInOrder extends \DomainException
{
    public static function forItem(int $orderItemId): self
    {
        return new self(sprintf('Order item %d does not belong to the order being returned.', $orderItemId));
    }
}
