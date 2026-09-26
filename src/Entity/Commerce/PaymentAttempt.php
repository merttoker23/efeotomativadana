<?php

declare(strict_types=1);

namespace App\Entity\Commerce;

use App\Module\Payment\PaymentState;
use App\Module\Payment\PaymentStateMachine;
use App\Module\Payment\SanitizedFailure;
use App\Shared\Money\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'commerce_payment_attempt')]
#[ORM\UniqueConstraint(name: 'uniq_payment_attempt_idempotency_key', columns: ['idempotency_key'])]
// Not unique: a provider that honours an idempotency key answers a repeat with the same
// reference, and that is the normal case. A duplicate reference therefore identifies a
// provider conversation to look up, not a constraint violation.
#[ORM\Index(name: 'idx_payment_attempt_provider_reference', columns: ['provider_key', 'provider_reference'])]
#[ORM\Index(name: 'idx_payment_attempt_payment_sequence', columns: ['payment_id', 'sequence'])]
#[ORM\UniqueConstraint(name: 'uniq_payment_attempt_return_token', columns: ['return_token'])]
/**
 * One external payment initiation. Attempts are never mutated into another attempt: a retry
 * is a new row, which is what makes provider callbacks idempotent and auditable.
 */
final class PaymentAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Payment::class, inversedBy: 'attempts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Payment $payment;

    #[ORM\Column(type: Types::INTEGER)]
    private int $sequence;

    #[ORM\Column(length: 80)]
    private string $idempotencyKey;

    #[ORM\Column(length: 64, unique: true)]
    private string $returnToken;

    #[ORM\Column(length: 50)]
    private string $providerKey;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $providerReference = null;

    #[ORM\Column(length: 30, enumType: PaymentState::class)]
    private PaymentState $state = PaymentState::Pending;

    #[ORM\Column(type: Types::BIGINT)]
    private int $amountMinorAmount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $failureCode = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $failureMessage = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $succeededAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @internal Constructed by {@see Payment::beginAttempt()}, which owns the invariants. */
    public function __construct(Payment $payment, int $sequence, string $idempotencyKey, string $returnToken, \DateTimeImmutable $createdAt)
    {
        $this->payment = $payment;
        $this->sequence = $sequence;
        $this->idempotencyKey = $idempotencyKey;
        $this->returnToken = $returnToken;
        $this->providerKey = $payment->providerKey();
        $this->amountMinorAmount = $payment->amount()->minorAmount();
        $this->currency = $payment->amount()->currency();
        $this->createdAt = $this->updatedAt = $createdAt;
    }

    public function id(): ?int { return $this->id; }
    public function payment(): Payment { return $this->payment; }
    public function orderNumber(): string { return $this->payment->order()->orderNumber(); }
    public function sequence(): int { return $this->sequence; }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
    public function returnToken(): string { return $this->returnToken; }
    public function providerKey(): string { return $this->providerKey; }
    public function providerReference(): ?string { return $this->providerReference; }
    public function state(): PaymentState { return $this->state; }
    public function amount(): Money { return Money::ofMinor($this->amountMinorAmount, $this->currency); }
    public function failure(): ?SanitizedFailure { return null === $this->failureCode ? null : SanitizedFailure::fromProvider($this->failureCode, $this->failureMessage, null); }
    public function succeededAt(): ?\DateTimeImmutable { return $this->succeededAt; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function assignProviderReference(string $reference): void
    {
        $reference = trim($reference);
        if ('' === $reference || mb_strlen($reference) > 120) {
            throw new \InvalidArgumentException('Payment attempt provider reference must contain between 1 and 120 characters.');
        }
        $this->providerReference = $reference;
    }

    /** @internal Only {@see Payment} drives attempt transitions, so the two never diverge. */
    public function markRequiresAction(): void
    {
        $this->transitionTo(PaymentState::RequiresAction);
    }

    /** @internal Only {@see Payment} drives attempt transitions, so the two never diverge. */
    public function markSucceeded(string $providerReference, Money $captured, \DateTimeImmutable $at): void
    {
        // The captured figure is recorded before the amount is judged, so a mismatch is
        // visible on the attempt as well as on the payment.
        $this->assertTransition(PaymentState::Succeeded);
        $this->assignProviderReference($providerReference);
        $this->state = PaymentState::Succeeded;
        $this->succeededAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * The provider took money that does not match this order. The attempt records the real
     * figure; the caller decides what the payment aggregate does with the difference.
     */
    public function markCapturedAmountMismatch(string $providerReference, \DateTimeImmutable $at): void
    {
        $this->assertTransition(PaymentState::CapturedAmountMismatch);
        $this->assignProviderReference($providerReference);
        $this->state = PaymentState::CapturedAmountMismatch;
        $this->succeededAt = $at;
        $this->updatedAt = $at;
    }
    /** @internal Only {@see Payment} drives attempt transitions, so the two never diverge. */
    public function markFailed(SanitizedFailure $failure): void
    {
        $this->assertTransition(PaymentState::Failed);
        $this->state = PaymentState::Failed;
        $this->failureCode = $failure->code();
        $this->failureMessage = '' === $failure->message() ? null : $failure->message();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @internal Only {@see Payment} drives attempt transitions, so the two never diverge. */
    public function markCancelled(string $reason): void
    {
        $this->assertTransition(PaymentState::Cancelled);
        $this->state = PaymentState::Cancelled;
        $reason = trim($reason);
        $this->failureCode = 'cancelled';
        $this->failureMessage = '' === $reason ? null : mb_substr($reason, 0, 500);
        $this->updatedAt = new \DateTimeImmutable();
    }

    private function assertTransition(PaymentState $next): void
    {
        (new PaymentStateMachine())->assertTransition($this->state, $next);
    }

    private function transitionTo(PaymentState $next): void
    {
        $this->assertTransition($next);
        $this->state = $next;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
