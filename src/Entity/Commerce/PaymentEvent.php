<?php

declare(strict_types=1);

namespace App\Entity\Commerce;

use App\Module\Payment\PaymentState;
use App\Shared\Money\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'commerce_payment_event')]
#[ORM\Index(name: 'idx_payment_event_payment_time', columns: ['payment_id', 'occurred_at'])]
/**
 * Immutable audit row for one payment state change. Payment history is deliberately kept
 * separate from {@see OrderStatusChange}: an operator must be able to answer "what did the
 * gateway say" without reading the order timeline, and vice versa.
 */
final class PaymentEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Payment::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Payment $payment;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $attemptSequence = null;

    #[ORM\Column(length: 30, enumType: PaymentState::class)]
    private PaymentState $toState;

    #[ORM\Column(length: 30)]
    private string $source;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $detail = null;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    public function __construct(Payment $payment, ?int $attemptSequence, PaymentState $toState, string $source, ?string $detail, \DateTimeImmutable $occurredAt)
    {
        $source = trim($source);
        if ('' === $source || mb_strlen($source) > 30) {
            throw new \InvalidArgumentException('Payment event source must contain between 1 and 30 characters.');
        }
        $detail = null === $detail ? null : trim($detail);
        if (null !== $detail && mb_strlen($detail) > 255) {
            throw new \InvalidArgumentException('Payment event detail must not exceed 255 characters.');
        }

        $this->payment = $payment;
        $this->attemptSequence = $attemptSequence;
        $this->toState = $toState;
        $this->source = $source;
        $this->detail = $detail;
        $this->occurredAt = $occurredAt;
    }

    public function id(): ?int { return $this->id; }
    public function payment(): Payment { return $this->payment; }
    public function attemptSequence(): ?int { return $this->attemptSequence; }
    public function toState(): PaymentState { return $this->toState; }
    public function source(): string { return $this->source; }
    public function detail(): ?string { return $this->detail; }
    public function occurredAt(): \DateTimeImmutable { return $this->occurredAt; }
}
