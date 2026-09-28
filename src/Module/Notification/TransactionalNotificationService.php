<?php

declare(strict_types=1);

namespace App\Module\Notification;

use App\Entity\Commerce\NotificationRecord;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Turns "this happened" into exactly one email, or none at all.
 *
 * The ordering here is the whole design:
 *
 * 1. the record is written **first**, in its own transaction;
 * 2. the mail is sent;
 * 3. the record is marked sent, or marked failed.
 *
 * Writing first is what makes a replay free. A webhook that arrives twice finds the unique index
 * already taken and returns without sending, so the customer's inbox sees one confirmation. Writing
 * *after* the send would leave a window in which a second, concurrent copy of the same event sees
 * no record and sends a second mail.
 *
 * The record is committed before the send rather than sharing the caller's transaction, because a
 * notification must never be able to roll back an order. An SMTP outage costs a missing email that
 * an operator can see and resend; it must not cost the customer's order.
 */
final readonly class TransactionalNotificationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private Environment $twig,
        private ClockInterface $clock,
    ) {
    }

    public function publish(NotificationEvent $event): NotificationResult
    {
        $record = $this->record($event);

        $this->entityManager->wrapInTransaction(function () use ($record): void {
            $this->entityManager->persist($record);
            $this->entityManager->flush();
        });

        $record->markAttempted();
        try {
            $this->mailer->send($this->compose($record));
        } catch (\Throwable $exception) {
            $this->finish($record, false, $exception->getMessage());

            // Re-raised so Messenger retries a transient outage. The row is already committed, so
            // the retry hits the unique index and is treated as a replay — which is exactly the
            // behaviour a transient failure should have: no second email, ever.
            throw $exception;
        }

        $this->finish($record, true, null);

        return NotificationResult::sent($record->type(), $record->recipient());
    }

    /**
     * The record, or a refusal if this logical notification already exists.
     *
     * The unique index is the real guard. The query is only here to turn a violation into a
     * readable answer instead of a driver exception.
     */
    private function record(NotificationEvent $event): NotificationRecord
    {
        $existing = $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM commerce_notification WHERE dedup_key = ?',
            [$event->dedupKey()],
        );
        if (false !== $existing && null !== $existing) {
            throw NotificationAlreadySent::forKey($event->dedupKey());
        }

        return new NotificationRecord($event, $event->payload, $this->now());
    }

    private function finish(NotificationRecord $record, bool $sent, ?string $failure): void
    {
        $this->entityManager->wrapInTransaction(function () use ($record, $sent, $failure): void {
            if ($sent) {
                $record->markSent($this->now());
            } else {
                $record->markFailed($failure ?? 'unknown');
            }
            $this->entityManager->persist($record);
            $this->entityManager->flush();
        });
    }

    private function compose(NotificationRecord $record): Email
    {
        $type = $record->type();
        $payload = $record->payload();
        $html = $this->twig->render(sprintf('emails/%s.html.twig', $type->templateStem()), [
            'type' => $type,
            'recipient_name' => (string) ($payload['customer_name'] ?? ''),
            'payload' => $payload,
            'subject' => $type->subject(),
        ]);

        return (new Email())
            ->from(new Address('no-reply@efeotomotivadana.com.tr', 'Efe Otomotiv Adana'))
            ->to($record->recipient())
            ->subject($type->subject())
            ->text($this->textFor($record))
            ->html($html);
    }

    /**
     * The plain-text alternative.
     *
     * Every notification is text as well as HTML: a storefront that mails only HTML is unreadable
     * to a screen reader used on a mail client and to anyone whose client refuses to render it.
     */
    private function textFor(NotificationRecord $record): string
    {
        return $this->twig->render(sprintf('emails/%s.txt.twig', $record->type()->templateStem()), [
            'type' => $record->type(),
            'recipient_name' => (string) ($record->payload()['customer_name'] ?? ''),
            'payload' => $record->payload(),
            'subject' => $record->type()->subject(),
        ])."\n";
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
