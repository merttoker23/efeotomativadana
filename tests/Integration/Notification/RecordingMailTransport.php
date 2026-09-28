<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Envelope;

/**
 * Records what would have gone out, so a test can count it.
 *
 * Symfony 8 removed the mailer's own `InMemoryTransport`, and the container's mailer is
 * `null://null`, which cannot tell a second email from a first. Counting the messages that
 * actually reached a transport is the only way to prove "we did not send it twice".
 */
final class RecordingMailTransport extends AbstractTransport
{
    /** @var list<SentMessage> */
    private array $sent = [];

    private ?\Throwable $failure = null;

    protected function doSend(SentMessage $message): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
        $this->sent[] = $message;
    }

    public function __toString(): string
    {
        return 'recording://';
    }

    /** @return list<SentMessage> */
    public function getSent(): array
    {
        return $this->sent;
    }

    public function count(): int
    {
        return \count($this->sent);
    }

    /** Make every subsequent send fail, the way an unreachable SMTP server would. */
    public function failWith(\Throwable $failure): void
    {
        $this->failure = $failure;
    }
}
