<?php

declare(strict_types=1);

namespace App\Module\Order;

enum OrderAddressRole: string
{
    case Shipping = 'shipping';
    case Billing = 'billing';
}
