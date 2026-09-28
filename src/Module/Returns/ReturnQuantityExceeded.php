<?php

declare(strict_types=1);

namespace App\Module\Returns;

/**
 * A return asked for more units of a line than are still free.
 *
 * Extends the domain exception the aggregate throws, so a caller that only cares that the request
 * was refused keeps working, while the controller can still tell this apart from a malformed line
 * and show the customer the ceiling.
 */
final class ReturnQuantityExceeded extends \DomainException
{
    public static function forLine(int $orderItemId, int $requested, int $available): self
    {
        return new self(sprintf(
            'Return line %d asks for %d unit(s) but only %d may still be returned.',
            $orderItemId,
            $requested,
            $available,
        ));
    }
}
