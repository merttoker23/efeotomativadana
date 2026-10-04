<?php

declare(strict_types=1);

namespace App\Module\Notification\Event;

use App\Entity\Commerce\CustomerOrder;

final readonly class OrderCancelled
{
    public function __construct(public CustomerOrder $order)
    {
    }
}
