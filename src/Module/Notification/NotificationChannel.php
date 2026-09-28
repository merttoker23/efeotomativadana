<?php

declare(strict_types=1);

namespace App\Module\Notification;

/**
 * How a notification leaves the store.
 *
 * One case today, and that is deliberate: a channel that cannot be reasoned about is a channel
 * nobody will reason about. Adding SMS or a push channel means deciding what a failed send costs
 * and whether a duplicate is acceptable, and neither question has an answer by default.
 */
enum NotificationChannel: string
{
    case Email = 'email';
}
