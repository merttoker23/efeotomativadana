<?php

declare(strict_types=1);

namespace App\Module\Notification\Event;

use App\Entity\Commerce\CustomerOrder;

/**
 * The provider reported a capture and the order was confirmed.
 *
 * Dispatched from the payment callback, which is the only place a payment can become successful, so
 * a capture can never produce two confirmations however the provider reports it.
 */
final readonly class PaymentCaptured
{
    public function __construct(public CustomerOrder $order)
    {
    }
}
