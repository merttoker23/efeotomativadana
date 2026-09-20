<?php

declare(strict_types=1);

namespace App\Module\Order;

enum OrderState: string
{
    case Placed = 'placed';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
}
