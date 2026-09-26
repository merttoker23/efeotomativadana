<?php

declare(strict_types=1);

namespace App\Entity\Commerce;

use App\Module\Checkout\GatewayPaymentOptionInterface;
use App\Module\Payment\PaymentState;
use App\Module\Payment\PaymentStateMachine;
use App\Module\Payment\SanitizedFailure;
use App\Repository\Commerce\PaymentRepository;
use App\Shared\Money\Money;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'commerce_payment')]
#[ORM\UniqueConstraint(name: 'uniq_payment_order', columns: ['order_id'])]
#[ORM\Index(name: 'idx_payment_state_updated', columns: ['state', 'updated_at'])]
/**
 * The payment aggregate for exactly one order. It owns the money the store expects to
 * collect, what was actually captured and given back, and its own audit trail. The order
 * keeps its own lifecycle; the two are only synchronised by the payment application service.
 */
final class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CustomerOrder::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CustomerOrder $order;

    #[ORM\Column(length: 50)]
    private string $providerKey;

    #[ORM\Column(length: 50)]
    private string $methodKey;

    #[ORM\Column(length: 30, enumType: PaymentState::class)]
    private PaymentState $state = PaymentState::Pending;

    #[ORM\Column(type: Types::BIGINT)]
    private int $amountMinorAmount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: Types::BIGINT)]
    private int $capturedMinorAmount = 0;

    #[ORM\Column(type: Types::BIGINT)]
    private int $refundedMinorAmount = 0;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $failureCode = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $failureMessage = null;

    /** @var Collection<int, PaymentAttempt> */
    #[ORM\OneToMany(mappedBy: 'payment', targetEntity: PaymentAttempt::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sequence' => 'ASC'])]
    private Collection $attempts;

    /** @var Collection<int, PaymentRefund> */
    #[ORM\OneToMany(mappedBy: 'payment', targetEntity: PaymentRefund::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $refunds;

    /** @var Collection<int, PaymentEvent> */
    #[ORM\OneToMany(mappedBy: 'payment', targetEntity: PaymentEvent::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $events;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * The clock every recorded event is stamped from. An audit trail whose rows share a
     * timestamp is not an audit trail, so the time is threaded through explicitly rather than
     * read from a field that is only updated after the event is written.
     */
    private \DateTimeImmutable $now;

    private function __construct(
        CustomerOrder $order,
        string $providerKey,
        Money $amount,
        \DateTimeImmutable $createdAt,
    ) {
        if (!$amount->equals($order->grandTotal())) {
            throw new \InvalidArgumentException('Payment amount must equal the order grand total.');
        }
        if (GatewayPaymentOptionInterface::CHECKOUT_KEY !== $order->paymentOptionKey()) {
            throw new \InvalidArgumentException('Payment requires a gateway-backed checkout payment method.');
        }

        $this->order = $order;
        $this->providerKey = trim($providerKey);
        $this->methodKey = $order->paymentOptionKey();
        $this->amountMinorAmount = $amount->minorAmount();
        $this->currency = $amount->currency();
        $this->attempts = new ArrayCollection();
        $this->refunds = new ArrayCollection();
        $this->events = new ArrayCollection();
        $this->createdAt = $this->updatedAt = $this->now = $createdAt;
        $this->record(PaymentState::Pending, 'payment', 'Payment aggregate created.');
    }

    public static function start(CustomerOrder $order, string $providerKey, Money $amount, \DateTimeImmutable $createdAt): self
    {
        return new self($order, $providerKey, $amount, $createdAt);
    }

    public function id(): ?int { return $this->id; }
    public function order(): CustomerOrder { return $this->order; }
    public function providerKey(): string { return $this->providerKey; }
    public function methodKey(): string { return $this->methodKey; }
    public function methodLabel(): string { return $this->order->paymentOptionLabel(); }
    public function state(): PaymentState { return $this->state; }
    public function amount(): Money { return Money::ofMinor($this->amountMinorAmount, $this->currency); }
    public function capturedAmount(): Money { return Money::ofMinor($this->capturedMinorAmount, $this->currency); }
    public function refundedAmount(): Money { return Money::ofMinor($this->refundedMinorAmount, $this->currency); }
    public function failure(): ?SanitizedFailure { return null === $this->failureCode ? null : SanitizedFailure::fromProvider($this->failureCode, $this->failureMessage, null); }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return list<PaymentAttempt> */
    public function attempts(): array { return array_values($this->attempts->toArray()); }

    /** @return list<PaymentRefund> */
    public function refunds(): array { return array_values($this->refunds->toArray()); }

    /** @return list<PaymentEvent> */
    public function events(): array { return array_values($this->events->toArray()); }

    public function latestAttempt(): ?PaymentAttempt
    {
        $attempts = $this->attempts();

        return [] === $attempts ? null : $attempts[array_key_last($attempts)];
    }

    public function refundableAmount(): Money
    {
        return $this->capturedAmount()->subtract($this->refundedAmount());
    }

    /** Whether everything that was captured has been given back. */
    public function isFullyRefunded(): bool
    {
        return PaymentState::Refunded === $this->state;
    }

    public function beginAttempt(string $idempotencyKey, ?Money $amount = null): PaymentAttempt
    {
        if (!$this->state->canBeRetried()) {
            throw new \DomainException(sprintf('A %s payment cannot be retried.', $this->state->value));
        }
        $idempotencyKey = trim($idempotencyKey);
        if ('' === $idempotencyKey || mb_strlen($idempotencyKey) > 80) {
            throw new \InvalidArgumentException('Payment idempotency key must contain between 1 and 80 characters.');
        }
        $expected = $this->amount();
        if (null !== $amount && !$amount->equals($expected)) {
            throw new \InvalidArgumentException('Payment attempt amount must equal the payment amount.');
        }
        foreach ($this->attempts as $existing) {
            if ($existing->idempotencyKey() === $idempotencyKey) {
                throw new \DomainException('A payment attempt with this idempotency key already exists.');
            }
        }

        $attempt = new PaymentAttempt($this, count($this->attempts) + 1, $idempotencyKey, bin2hex(random_bytes(32)), new \DateTimeImmutable());
        $this->attempts->add($attempt);

        return $attempt;
    }

    public function markRequiresAction(PaymentAttempt $attempt, \DateTimeImmutable $at): void
    {
        $this->assertOwnsAttempt($attempt);
        $this->assertNotCaptured();
        $attempt->markRequiresAction();
        // The payment may already be `failed` when a new attempt sends the customer back to
        // the provider, so this move is not a state-machine transition of the payment.
        $this->now = $at;
        $this->state = PaymentState::RequiresAction;
        $this->clearFailure();
        $this->record(PaymentState::RequiresAction, 'gateway', 'Cardholder action required.');
        $this->updatedAt = $at;
    }

    public function markSucceeded(PaymentAttempt $attempt, string $providerReference, Money $captured, \DateTimeImmutable $at): void
    {
        $this->assertOwnsAttempt($attempt);
        $this->assertNotCaptured();
        if (!$captured->equals($this->amount())) {
            throw new \DomainException('Captured amount does not match the payment amount.');
        }
        $this->now = $at;
        $attempt->markSucceeded($providerReference, $captured, $at);
        $this->capturedMinorAmount = $captured->minorAmount();
        $this->clearFailure();
        $this->transition(PaymentState::Succeeded, 'gateway', 'Provider confirmed the capture.');
        $this->updatedAt = $at;
    }

    /**
     * The provider captured a real amount that does not match this order's total.
     *
     * The captured figure is persisted exactly as the provider reported it and the payment
     * stays refundable, because the money left the customer. Recording "captured 0" here
     * would make real money invisible and unrecoverable from inside the application.
     */
    public function markCapturedAmountMismatch(PaymentAttempt $attempt, string $providerReference, Money $captured, \DateTimeImmutable $at): void
    {
        $this->assertOwnsAttempt($attempt);
        if (0 !== $this->capturedMinorAmount) {
            throw new \DomainException('Payment capture amount is already recorded.');
        }
        if ($captured->isZero()) {
            throw new \DomainException('A mismatched capture cannot be nothing.');
        }
        $this->now = $at;
        $attempt->markCapturedAmountMismatch($providerReference, $at);
        $this->capturedMinorAmount = $captured->minorAmount();
        $this->failureCode = 'amount_mismatch';
        $this->failureMessage = mb_substr(sprintf(
            'The provider captured %d minor units instead of the expected %d.',
            $captured->minorAmount(),
            $this->amountMinorAmount,
        ), 0, 500);
        $this->transition(PaymentState::CapturedAmountMismatch, 'gateway', 'Provider captured a different amount than this order expects.');
        $this->updatedAt = $at;
    }

    public function markFailed(PaymentAttempt $attempt, SanitizedFailure $failure, \DateTimeImmutable $at): void
    {
        $this->assertOwnsAttempt($attempt);
        $this->now = $at;
        $attempt->markFailed($failure);
        $this->failureCode = $failure->code();
        $this->failureMessage = '' === $failure->message() ? null : $failure->message();
        $this->transition(PaymentState::Failed, 'gateway', $failure->code());
        $this->updatedAt = $at;
    }

    public function markCancelled(PaymentAttempt $attempt, string $reason, \DateTimeImmutable $at): void
    {
        $this->assertOwnsAttempt($attempt);
        $this->assertNotCaptured();
        $this->now = $at;
        $attempt->markCancelled($reason);
        $this->failureCode = 'cancelled';
        $this->failureMessage = '' === trim($reason) ? null : mb_substr(trim($reason), 0, 500);
        $this->transition(PaymentState::Cancelled, 'store', 'Payment cancelled by the store.');
        $this->updatedAt = $at;
    }

    /**
     * Record a refund the provider refused. No money moved, so the captured and refunded
     * counters are untouched, but the attempt is still a fact about this payment and belongs in
     * its history — otherwise an operator has no record that a refund was ever attempted.
     */
    public function recordRejectedRefund(Money $amount, string $reason, SanitizedFailure $failure, \DateTimeImmutable $at): PaymentRefund
    {
        $refund = new PaymentRefund($this, $amount, $this->providerKey, $this->rejectedRefundReference(), $reason, $at);
        $refund->markFailed($failure);
        $this->now = $at;
        $this->refunds->add($refund);
        $this->record($this->state, 'gateway', sprintf('Refund refused: %s', $failure->code()));

        return $refund;
    }

    /**
     * A refused refund has no provider reference, so it is given a local one. The uniqueness
     * index is per provider, which keeps a repeated refusal from colliding with a real refund.
     */
    private function rejectedRefundReference(): string
    {
        return sprintf('rejected-%d-%d', $this->refunds->count() + 1, $this->now->getTimestamp());
    }

    public function recordRefund(Money $amount, string $providerReference, string $reason, \DateTimeImmutable $at): PaymentRefund
    {
        if (!$this->state->isRefundable()) {
            throw new \DomainException(sprintf('A %s payment cannot be refunded.', $this->state->value));
        }
        if ($amount->currency() !== $this->amount()->currency()) {
            throw new \DomainException('Refund currency does not match the payment currency.');
        }
        $refundable = $this->refundableAmount();
        if ($amount->minorAmount() > $refundable->minorAmount()) {
            throw new \DomainException('Refund exceeds the refundable amount.');
        }

        $refund = new PaymentRefund($this, $amount, $this->providerKey, $providerReference, $reason, $at);
        $refund->markCompleted();
        $this->now = $at;
        $this->refunds->add($refund);
        $this->refundedMinorAmount += $amount->minorAmount();
        $this->transition(
            $this->refundableAmount()->isZero() ? PaymentState::Refunded : PaymentState::PartiallyRefunded,
            'store',
            sprintf('Refunded %d minor units.', $amount->minorAmount()),
        );
        $this->updatedAt = $at;

        return $refund;
    }

    private function assertOwnsAttempt(PaymentAttempt $attempt): void
    {
        if (!$this->attempts->contains($attempt)) {
            throw new \DomainException('Payment attempt does not belong to this payment.');
        }
    }

    /**
     * Nothing that touches the money state may run twice. The guard lives on the aggregate
     * rather than on each call site, so no path can quietly double a capture.
     */
    private function assertNotCaptured(): void
    {
        if (0 !== $this->capturedMinorAmount) {
            throw new \DomainException('Payment capture amount is already recorded.');
        }
    }

    private function clearFailure(): void
    {
        $this->failureCode = null;
        $this->failureMessage = null;
    }

    private function transition(PaymentState $next, string $source, ?string $detail): void
    {
        (new PaymentStateMachine())->assertTransition($this->state, $next);
        $this->state = $next;
        $this->record($next, $source, $detail);
    }

    private function record(PaymentState $next, string $source, ?string $detail): void
    {
        $this->events->add(new PaymentEvent($this, $this->latestAttempt()?->sequence(), $next, $source, $detail, $this->now));
    }
}
