<?php

declare(strict_types=1);

namespace App\Entity\Commerce;

use App\Module\Payment\PaymentRefundState;
use App\Module\Payment\SanitizedFailure;
use App\Shared\Money\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'commerce_payment_refund')]
#[ORM\UniqueConstraint(name: 'uniq_payment_refund_provider_reference', columns: ['provider_key', 'provider_reference'])]
#[ORM\Index(name: 'idx_payment_refund_payment_time', columns: ['payment_id', 'created_at'])]
final class PaymentRefund
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Payment::class, inversedBy: 'refunds')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Payment $payment;

    #[ORM\Column(type: Types::BIGINT)]
    private int $amountMinorAmount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 50)]
    private string $providerKey;

    #[ORM\Column(length: 120)]
    private string $providerReference;

    #[ORM\Column(length: 20, enumType: PaymentRefundState::class)]
    private PaymentRefundState $state = PaymentRefundState::Requested;

    #[ORM\Column(length: 255)]
    private string $reason;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $failureCode = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $failureMessage = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Payment $payment,
        Money $amount,
        string $providerKey,
        string $providerReference,
        string $reason,
        \DateTimeImmutable $createdAt,
    ) {
        $reason = trim($reason);
        if ('' === $reason || mb_strlen($reason) > 255) {
            throw new \InvalidArgumentException('Payment refund reason must contain between 1 and 255 characters.');
        }

        $this->payment = $payment;
        $this->amountMinorAmount = $amount->minorAmount();
        $this->currency = $amount->currency();
        $this->providerKey = trim($providerKey);
        $this->providerReference = trim($providerReference);
        $this->reason = $reason;
        $this->createdAt = $createdAt;
    }

    public function id(): ?int { return $this->id; }
    public function payment(): Payment { return $this->payment; }
    public function amount(): Money { return Money::ofMinor($this->amountMinorAmount, $this->currency); }
    public function providerKey(): string { return $this->providerKey; }
    public function providerReference(): string { return $this->providerReference; }
    public function state(): PaymentRefundState { return $this->state; }
    public function reason(): string { return $this->reason; }
    public function failure(): ?SanitizedFailure { return null === $this->failureCode ? null : SanitizedFailure::fromProvider($this->failureCode, $this->failureMessage, null); }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }

    public function markCompleted(): void
    {
        $this->assertOpen();
        $this->state = PaymentRefundState::Completed;
    }

    public function markFailed(SanitizedFailure $failure): void
    {
        $this->assertOpen();
        $this->state = PaymentRefundState::Failed;
        $this->failureCode = $failure->code();
        $this->failureMessage = '' === $failure->message() ? null : $failure->message();
    }

    private function assertOpen(): void
    {
        if (PaymentRefundState::Requested !== $this->state) {
            throw new \DomainException(sprintf('Refund is already %s.', $this->state->value));
        }
    }
}
