<?php

declare(strict_types=1);

namespace App\Module\Payment;

enum PaymentRefundState: string
{
    case Requested = 'requested';
    case Completed = 'completed';
    case Failed = 'failed';
}
