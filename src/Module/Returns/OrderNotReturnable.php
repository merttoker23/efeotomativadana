<?php

declare(strict_types=1);

namespace App\Module\Returns;

/** The order cannot be returned at all; {@see ReturnPolicy} carries the reason. */
final class OrderNotReturnable extends \DomainException
{
    public static function for(\App\Entity\Commerce\CustomerOrder $order, ReturnIneligibility $reason): self
    {
        return new self(sprintf('Order %s cannot be returned: %s.', $order->orderNumber(), $reason->value));
    }
}
