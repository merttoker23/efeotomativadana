<?php

declare(strict_types=1);

namespace App\Module\Notification;

final readonly class NotificationResult
{
    public bool $wasSent;

    private function __construct(public NotificationType $type, public string $recipient)
    {
        $this->wasSent = true;
    }

    public static function sent(NotificationType $type, string $recipient): self
    {
        return new self($type, $recipient);
    }
}
