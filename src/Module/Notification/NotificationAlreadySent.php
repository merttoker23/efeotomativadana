<?php

declare(strict_types=1);

namespace App\Module\Notification;

/**
 * This logical notification has already gone out.
 *
 * Raised rather than swallowed so a caller can see that its event was a replay — a payment webhook
 * delivered twice, an operator pressing a button again — and decide whether that was expected,
 * instead of believing it just sent a confirmation email to a customer who already has one.
 */
final class NotificationAlreadySent extends \RuntimeException
{
    public static function forKey(string $dedupKey): self
    {
        return new self(sprintf('Notification %s was already sent.', $dedupKey));
    }
}
