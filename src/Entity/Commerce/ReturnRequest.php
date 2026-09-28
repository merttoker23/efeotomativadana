<?php

declare(strict_types=1);

namespace App\Entity\Commerce;

use App\Entity\Customer\CustomerUser;
use App\Module\Returns\ReturnState;
use App\Module\Returns\ReturnStateMachine;
use App\Repository\Commerce\ReturnRequestRepository;
use App\Shared\Money\Money;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One customer's request to send part or all of an order back.
 *
 * A separate aggregate from {@see CustomerOrder}, for the same reason the shipment is: the order is
 * the commercial promise and stays true. A return is a conversation about it that may end in the
 * goods coming back, or in nothing, and the order must be readable either way. Nothing on this
 * aggregate moves money. {@see \App\Module\Payment\PaymentRefundService} does that, explicitly,
 * and the reference it produced is recorded here as a fact rather than an action.
 *
 * The items carry their own copy of the SKU, name, unit price and tax rate taken from the order
 * item, so a return raised in March is still legible after the catalogue has been rewritten.
 */
#[ORM\Entity(repositoryClass: ReturnRequestRepository::class)]
#[ORM\Table(name: 'commerce_return_request')]
#[ORM\UniqueConstraint(name: 'uniq_return_request_number', columns: ['return_number'])]
#[ORM\Index(name: 'idx_return_request_customer_created', columns: ['customer_id', 'created_at'])]
#[ORM\Index(name: 'idx_return_request_order', columns: ['order_id'])]
#[ORM\Index(name: 'idx_return_request_state_updated', columns: ['state', 'updated_at'])]
final class ReturnRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $returnNumber;

    #[ORM\ManyToOne(targetEntity: CustomerOrder::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CustomerOrder $order;

    #[ORM\ManyToOne(targetEntity: CustomerUser::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CustomerUser $customer;

    #[ORM\Column(length: 30, enumType: ReturnState::class)]
    private ReturnState $state = ReturnState::Requested;

    #[ORM\Column(length: 1000)]
    private string $customerReason;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $staffNote = null;

    /** @var Collection<int, ReturnRequestItem> */
    #[ORM\OneToMany(mappedBy: 'returnRequest', targetEntity: ReturnRequestItem::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $items;

    /** @var Collection<int, ReturnEvent> */
    #[ORM\OneToMany(mappedBy: 'returnRequest', targetEntity: ReturnEvent::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $events;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $receivedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $refundedAt = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $refundMinorAmount = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $refundCurrency = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $refundReference = null;

    /**
     * The clock every recorded event is stamped from.
     *
     * An audit trail whose rows share one timestamp is not an audit trail, so the time is threaded
     * through each transition rather than read from a field only updated after the event is
     * written. Same reason as {@see Shipment::$now}.
     */
    private \DateTimeImmutable $now;

    private function __construct(CustomerOrder $order, string $returnNumber, string $customerReason, \DateTimeImmutable $at, ?string $actorEmail)
    {
        $this->order = $order;
        $this->customer = $order->customer();
        $this->returnNumber = self::assertNumber($returnNumber);
        $this->customerReason = self::assertReason($customerReason, 'A return request requires a reason.');
        $this->items = new ArrayCollection();
        $this->events = new ArrayCollection();
        $this->createdAt = $this->updatedAt = $this->now = $at;
        $this->record(ReturnState::Requested, 'customer', null, $actorEmail);
    }

    public static function open(CustomerOrder $order, string $returnNumber, \DateTimeImmutable $at, string $customerReason, ?string $actorEmail = null): self
    {
        return new self($order, $returnNumber, $customerReason, $at, $actorEmail);
    }

    public function id(): ?int { return $this->id; }
    public function returnNumber(): string { return $this->returnNumber; }
    public function order(): CustomerOrder { return $this->order; }
    public function orderNumber(): string { return $this->order->orderNumber(); }
    public function customer(): CustomerUser { return $this->customer; }
    public function customerEmail(): string { return $this->order->customerEmail(); }
    public function customerName(): string { return $this->order->customerName(); }
    public function state(): ReturnState { return $this->state; }
    public function customerReason(): string { return $this->customerReason; }
    public function staffNote(): ?string { return $this->staffNote; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function decidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }
    public function cancelledAt(): ?\DateTimeImmutable { return $this->cancelledAt; }
    public function receivedAt(): ?\DateTimeImmutable { return $this->receivedAt; }
    public function refundedAt(): ?\DateTimeImmutable { return $this->refundedAt; }
    public function refundReference(): ?string { return $this->refundReference; }

    public function refundMinorAmount(): ?Money
    {
        if (null === $this->refundMinorAmount || null === $this->refundCurrency) {
            return null;
        }

        return Money::ofMinor($this->refundMinorAmount, $this->refundCurrency);
    }

    public function totalValue(): Money
    {
        $currency = [] === $this->items() ? $this->order->grandTotal()->currency() : $this->items()[0]->unitGross()->currency();
        $total = Money::ofMinor(0, $currency);
        foreach ($this->items() as $item) {
            $total = $total->add($item->lineGross());
        }

        return $total;
    }

    /** @return list<ReturnRequestItem> */
    public function items(): array { return array_values($this->items->toArray()); }

    /** @return list<ReturnEvent> */
    public function events(): array { return array_values($this->events->toArray()); }

    public function isOpen(): bool { return $this->state->isOpen(); }
    public function isClosed(): bool { return $this->state->isTerminal(); }
    public function isAwaitingRefund(): bool { return $this->state->awaitsRefund(); }

    /** Whether the customer may still take this request back. */
    public function canBeWithdrawn(): bool
    {
        return in_array($this->state, [ReturnState::Requested, ReturnState::Approved], true);
    }

    public function addItem(OrderItem $orderItem, int $quantity, string $reason, Money $unitGross, int $taxRateBasisPoints): void
    {
        if ($quantity < 1) {
            throw new \DomainException('A return line needs a quantity of at least one.');
        }
        foreach ($this->items as $existing) {
            if ($existing->orderItem() === $orderItem) {
                throw new \DomainException('An order line can appear only once in a return request.');
            }
        }

        $this->items->add(new ReturnRequestItem($this, $orderItem, $quantity, $reason, $unitGross, $taxRateBasisPoints));
    }

    /**
     * Add the line as the customer sees it: unit price and tax rate are read from the order item
     * rather than supplied, so a form can never quote a return at a price the customer chose.
     *
     * Records no event. Composing which lines a return covers is part of *being* the request, and
     * the request's opening event already says so. A trail of one row per line would bury the four
     * rows that actually record decisions.
     *
     * @return bool false when the line was refused, with nothing written
     */
    public function addItemFromOrder(OrderItem $orderItem, int $quantity, string $reason): bool
    {
        try {
            $this->addItem($orderItem, $quantity, $reason, $orderItem->unitGross(), $orderItem->taxRateBasisPoints());
        } catch (\DomainException) {
            return false;
        }

        return true;
    }

    public function approve(string $staffNote, \DateTimeImmutable $at, ?string $actorEmail = null): void
    {
        $this->assertHasItems();
        $staffNote = self::optionalNote($staffNote);
        $this->now = $at;
        $this->transition(ReturnState::Approved, 'store', $staffNote ?? 'Return approved by the store.', $actorEmail);
        $this->staffNote = $staffNote;
        $this->decidedAt ??= $at;
        $this->updatedAt = $at;
    }

    /**
     * A refusal always carries a reason.
     *
     * A customer whose return is declined is entitled to know why, so an empty note is refused here
     * rather than rendered as a blank line on a screen they are already unhappy about.
     */
    public function reject(string $staffNote, \DateTimeImmutable $at, ?string $actorEmail = null): void
    {
        $this->assertHasItems();
        $staffNote = self::assertReason($staffNote, 'A rejection requires a reason the customer can read.');
        $this->now = $at;
        $this->transition(ReturnState::Rejected, 'store', $staffNote, $actorEmail);
        $this->staffNote = $staffNote;
        $this->decidedAt ??= $at;
        $this->updatedAt = $at;
    }

    public function withdraw(\DateTimeImmutable $at, ?string $actorEmail = null): void
    {
        $this->now = $at;
        $this->transition(ReturnState::Withdrawn, 'store', 'Return withdrawn.', $actorEmail);
        $this->cancelledAt = $at;
        $this->updatedAt = $at;
    }

    public function markReceived(\DateTimeImmutable $at, ?string $actorEmail = null): void
    {
        $this->now = $at;
        $this->transition(ReturnState::Received, 'store', 'Goods received by the store.', $actorEmail);
        $this->receivedAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * Record a refund that a payment service already issued.
     *
     * This aggregate never initiates one and never talks to a provider. The reference is a fact
     * about money that has moved elsewhere, kept here so a return is not left looking unfinished
     * after the customer has been paid back.
     */
    public function markRefunded(int $amountMinor, string $currency, string $refundReference, \DateTimeImmutable $at, ?string $actorEmail = null): void
    {
        if ($amountMinor < 1) {
            throw new \DomainException('A recorded refund must be for a positive amount.');
        }
        $refundReference = trim($refundReference);
        if ('' === $refundReference) {
            throw new \DomainException('A recorded refund requires the provider reference it was issued under.');
        }
        $this->now = $at;
        $this->transition(ReturnState::Refunded, 'store', sprintf('Refund issued (%s).', $refundReference), $actorEmail);
        $this->refundMinorAmount = $amountMinor;
        $this->refundCurrency = strtoupper($currency);
        $this->refundReference = $refundReference;
        $this->refundedAt = $at;
        $this->updatedAt = $at;
    }

    private function assertHasItems(): void
    {
        if ([] === $this->items()) {
            throw new \DomainException('A return request must name at least one order line.');
        }
    }

    private function transition(ReturnState $next, string $source, ?string $detail, ?string $actorEmail = null): void
    {
        (new ReturnStateMachine())->assertTransition($this->state, $next);
        $this->state = $next;
        $this->record($next, $source, $detail, $actorEmail);
    }

    private function record(ReturnState $toState, string $source, ?string $detail, ?string $actorEmail = null): void
    {
        $this->events->add(new ReturnEvent($this, $toState, $source, $detail, $this->now, $actorEmail));
    }

    private static function assertNumber(string $value): string
    {
        $value = trim($value);
        if (1 !== preg_match('/^RET-\d{8}-[0-9A-F]{12}$/', $value)) {
            throw new \DomainException('A return number must look like RET-YYYYMMDD-XXXXXXXXXXXX.');
        }

        return $value;
    }

    private static function assertReason(string $value, string $message): string
    {
        $value = trim($value);
        if ('' === $value || mb_strlen($value) > 1000) {
            throw new \DomainException($message);
        }

        return $value;
    }

    private static function optionalNote(string $value): ?string
    {
        $value = trim($value);

        return '' === $value || mb_strlen($value) > 1000 ? null : $value;
    }
}
