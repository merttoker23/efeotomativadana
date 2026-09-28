<?php

declare(strict_types=1);

namespace App\Module\Notification\Event;

use App\Entity\Commerce\CustomerOrder;

/**
 * An order was placed and committed.
 *
 * The notification is published from here rather than from checkout, because checkout already
 * redirects to the payment page and a mailer is not a thing a request should be waiting on.
 */
final readonly class OrderPlaced
{
    public function __construct(public CustomerOrder $order)
    {
    }
}
