<?php

declare(strict_types=1);

namespace App\Entity\Commerce;

use App\Module\Notification\NotificationChannel;
use App\Module\Notification\NotificationEvent;
use App\Module\Notification\NotificationType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The record that a notification was asked for, sent, and how it went.
 *
 * This row — not the email — is the deduplication mechanism. A unique index on `dedup_key` means a
 * replayed event loses the race in the database, which is the only place that can decide it: two
 * payment webhooks arriving in the same millisecond both pass an application-level "have I sent
 * this?" check before either has written anything.
 *
 * It also answers the question an operator actually has at 23:00 — "did the customer get the
 * message?" — which a Mailpit log does not, because a queued message is not a delivered one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'commerce_notification')]
#[ORM\UniqueConstraint(name: 'uniq_notification_dedup_key', columns: ['dedup_key'])]
#[ORM\Index(name: 'idx_notification_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_notification_type_reference', columns: ['type', 'subject_reference'])]
final class NotificationRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $dedupKey;

    #[ORM\Column(length: 40, enumType: NotificationType::class)]
    private NotificationType $type;

    #[ORM\Column(length: 120)]
    private string $subjectReference;

    #[ORM\Column(length: 180)]
    private string $recipient;

    #[ORM\Column(length: 20, enumType: NotificationChannel::class)]
    private NotificationChannel $channel = NotificationChannel::Email;

    /** @var array<string, scalar> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $failureMessage = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    /** @param array<string, scalar> $payload */
    public function __construct(NotificationEvent $event, array $payload, \DateTimeImmutable $at)
    {
        $this->dedupKey = mb_substr($event->dedupKey(), 0, 180);
        $this->type = $event->type;
        $this->subjectReference = $event->subjectReference;
        $this->recipient = $event->recipient;
        $this->payload = $payload;
        $this->createdAt = $at;
    }

    public function id(): ?int { return $this->id; }
    public function dedupKey(): string { return $this->dedupKey; }
    public function type(): NotificationType { return $this->type; }
    public function subjectReference(): string { return $this->subjectReference; }
    public function recipient(): string { return $this->recipient; }
    public function channel(): NotificationChannel { return $this->channel; }

    /** @return array<string, scalar> */
    public function payload(): array { return $this->payload; }

    public function attempts(): int { return $this->attempts; }
    public function failureMessage(): ?string { return $this->failureMessage; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function sentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function wasSent(): bool { return null !== $this->sentAt; }

    public function markAttempted(): void
    {
        ++$this->attempts;
    }

    public function markSent(\DateTimeImmutable $at): void
    {
        $this->sentAt = $at;
        $this->failureMessage = null;
    }

    public function markFailed(string $message): void
    {
        $this->failureMessage = mb_substr($message, 0, 500);
    }
}
