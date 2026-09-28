<?php

declare(strict_types=1);

namespace App\Module\Returns;

/**
 * The same order line was submitted twice in one request.
 *
 * A form mistake rather than a boundary crossing, so it gets its own type instead of borrowing
 * {@see ReturnLineNotInOrder}. The aggregate refuses the duplicate too; this exists because
 * `addItemFromOrder()` deliberately absorbs a refusal, and a silently dropped line would be
 * reported to the customer as accepted.
 */
final class ReturnLineDuplicated extends \DomainException
{
    public static function forItem(int $orderItemId): self
    {
        return new self(sprintf('Order item %d was submitted twice in the same return request.', $orderItemId));
    }
}
