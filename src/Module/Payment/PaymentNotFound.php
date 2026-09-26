<?php

declare(strict_types=1);

namespace App\Module\Payment;

/** The order has no payment record, so the requested payment operation cannot apply. */
final class PaymentNotFound extends \RuntimeException
{
}
