<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway;

enum RefundStatus: string
{
    /** The provider accepted the refund but has not confirmed it yet. */
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
