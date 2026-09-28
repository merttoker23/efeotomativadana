<?php

declare(strict_types=1);

namespace App\Entity\Commerce;

use App\Module\Order\OrderState;
use App\Module\Payment\SanitizedFailure;
use App\Module\Shipping\LocalManualShippingMethod;
use App\Module\Shipping\ShipmentState;
use App\Module\Shipping\ShipmentStateMachine;
use App\Module\Shipping\ShipmentText;
use App\Repository\Commerce\ShipmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The shipping half of one order: where its parcel is, who is carrying it and what to tell the
 * customer.
 *
 * A shipment is a separate aggregate from {@see CustomerOrder} and from {@see Payment}. The order
 * is the commercial promise; this is the physical one. They are related — a shipment belongs to an
 * order and can only be created for a confirmed one — but they are never merged: a carrier can
 * lose a parcel while the order stands confirmed, and a customer can be refunded while the parcel
 * is still on its way. No method on this aggregate ever touches the order, and the order's own
 * lifecycle knows nothing about parcels.
 *
 * One order has at most one shipment, enforced by a unique index on `order_id`. A replayed
 * create message, a double-clicked button and a retried Messenger delivery all therefore converge
 * on the same row rather than producing three parcels.
 */
#[ORM\Entity(repositoryClass: ShipmentRepository::class)]
#[ORM\Table(name: 'commerce_shipment')]
#[ORM\UniqueConstraint(name: 'uniq_shipment_order', columns: ['order_id'])]
#[ORM\UniqueConstraint(name: 'uniq_shipment_idempotency_key', columns: ['idempotency_key'])]
#[ORM\Index(name: 'idx_shipment_state_updated', columns: ['state', 'updated_at'])]
#[ORM\Index(name: 'idx_shipment_provider_reference', columns: ['provider_key', 'provider_reference'])]
final class Shipment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CustomerOrder::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CustomerOrder $order;

    #[ORM\Column(length: 80)]
    private string $idempotencyKey;

    #[ORM\Column(length: 50)]
    private string $methodKey;

    #[ORM\Column(length: 120)]
    private string $methodLabel;

    #[ORM\Column(length: 50)]
    private string $providerKey;

    #[ORM\Column(length: 30, enumType: ShipmentState::class)]
    private ShipmentState $state = ShipmentState::Pending;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $providerReference = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $trackingNumber = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $failureCode = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $failureMessage = null;

    /** @var Collection<int, ShipmentEvent> */
    #[ORM\OneToMany(mappedBy: 'shipment', targetEntity: ShipmentEvent::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $events;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readyAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $inTransitAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    /**
     * The clock every recorded event is stamped from. An audit trail whose rows share a timestamp
     * is not an audit trail, so the time is threaded through explicitly rather than read from a
     * field that is only updated after the event is written.
     */
    private \DateTimeImmutable $now;

    private function __construct(
        CustomerOrder $order,
        string $methodKey,
        string $methodLabel,
        string $providerKey,
        \DateTimeImmutable $createdAt,
        ?string $actorEmail,
    ) {
        if (OrderState::Confirmed !== $order->state()) {
            throw new \DomainException('Only a confirmed order can be shipped.');
        }
        $methodKey = self::required($methodKey, 'Shipment method key must contain between 1 and 50 characters.');
        $methodLabel = self::required($methodLabel, 'Shipment method label must contain between 1 and 120 characters.');
        $providerKey = self::required($providerKey, 'Shipment provider key must contain between 1 and 50 characters.');

        $this->order = $order;
        $this->idempotencyKey = self::idempotencyKeyFor($order->orderNumber());
        $this->methodKey = $methodKey;
        $this->methodLabel = $methodLabel;
        $this->providerKey = $providerKey;
        $this->events = new ArrayCollection();
        $this->createdAt = $this->updatedAt = $this->now = $createdAt;
        $this->record(ShipmentState::Pending, 'store', 'Shipment record created.', $actorEmail);
    }

    public static function start(
        CustomerOrder $order,
        string $methodKey,
        string $methodLabel,
        string $providerKey,
        \DateTimeImmutable $createdAt,
        ?string $actorEmail = null,
    ): self {
        return new self($order, $methodKey, $methodLabel, $providerKey, $createdAt, $actorEmail);
    }

    /**
     * The parcel's identity, at the carrier and in the database alike.
     *
     * Derived from the order rather than generated, so the second and third attempt to ship one
     * order present the carrier with the very same key and are answered with the very same
     * reference.
     */
    public static function idempotencyKeyFor(string $orderNumber): string
    {
        return 'shipment-'.trim($orderNumber);
    }

    public function id(): ?int { return $this->id; }
    public function order(): CustomerOrder { return $this->order; }
    public function orderNumber(): string { return $this->order->orderNumber(); }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
    public function methodKey(): string { return $this->methodKey; }
    public function methodLabel(): string { return $this->methodLabel; }
    public function providerKey(): string { return $this->providerKey; }
    public function state(): ShipmentState { return $this->state; }
    public function providerReference(): ?string { return $this->providerReference; }
    public function trackingNumber(): ?string { return $this->trackingNumber; }
    public function failure(): ?SanitizedFailure { return null === $this->failureCode ? null : SanitizedFailure::fromProvider($this->failureCode, $this->failureMessage, null); }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function readyAt(): ?\DateTimeImmutable { return $this->readyAt; }
    public function inTransitAt(): ?\DateTimeImmutable { return $this->inTransitAt; }
    public function deliveredAt(): ?\DateTimeImmutable { return $this->deliveredAt; }
    public function cancelledAt(): ?\DateTimeImmutable { return $this->cancelledAt; }

    /** @return list<ShipmentEvent> */
    public function events(): array { return array_values($this->events->toArray()); }

    /**
     * Whether the provider still has to be asked to take this parcel.
     *
     * A retryable failure counts, because the parcel still exists and the same key presented again
     * is the attempt the carrier should recognise. A permanent failure does not: nothing about the
     * parcel changed, so a redelivered message asking again would only spend the carrier's
     * patience. Getting there again is a decision, and it is an operator's — see
     * {@see retryCreation()}.
     */
    public function awaitsProviderCreation(): bool
    {
        if (ShipmentState::Pending === $this->state) {
            return true;
        }

        return ShipmentState::Failed === $this->state && true === $this->failure()?->isRetryable();
    }

    /**
     * An operator decides to try again after a failure nothing will fix on its own.
     *
     * The same row under the same idempotency key, so a carrier that already knows the parcel
     * answers with the same one rather than creating a second. The failure is cleared only here,
     * where a human has decided it is worth another attempt.
     */
    public function retryCreation(\DateTimeImmutable $at, ?string $actorEmail = null): void
    {
        if (ShipmentState::Failed !== $this->state) {
            throw new \DomainException(sprintf('A %s shipment cannot be retried.', $this->state->value));
        }
        $this->now = $at;
        $this->transition(ShipmentState::Pending, 'store', 'Retry requested by an operator.', $actorEmail);
        $this->updatedAt = $at;
    }

    /**
     * Whether the store fulfils this parcel itself.
     *
     * A fact about the shipment rather than about the store's current configuration: switching
     * carriers must not change how an order that was already handed to a van is described.
     */
    public function isHandFulfilledByStore(): bool
    {
        return LocalManualShippingMethod::MANUAL_PROVIDER_KEY === $this->providerKey;
    }

    public function canBeCancelled(): bool
    {
        return $this->state->isCancellable();
    }

    /**
     * The parcel was handed over — to a carrier, or to the store's own driver.
     *
     * A local hand-fulfilment has no carrier reference and passes null, which is why the reference
     * is optional here and mandatory on a provider's accepted outcome instead.
     */
    public function markReady(?string $providerReference, ?string $trackingNumber, \DateTimeImmutable $at, ?string $actorEmail = null): void
    {
        $this->now = $at;
        $this->transition(ShipmentState::Ready, 'provider', 'Parcel handed over.', $actorEmail);
        $providerReference = ShipmentText::code($providerReference);
        if (null !== $providerReference) {
            $this->providerReference = $providerReference;
        }
        $this->applyTrackingNumber($trackingNumber);
        $this->readyAt ??= $at;
        $this->updatedAt = $at;
    }

    public function markInTransit(\DateTimeImmutable $at, ?string $actorEmail = null): void
    {
        $this->now = $at;
        $this->transition(ShipmentState::InTransit, 'store', 'Parcel is on the road.', $actorEmail);
        $this->inTransitAt ??= $at;
        $this->updatedAt = $at;
    }

    public function markDelivered(\DateTimeImmutable $at, ?string $actorEmail = null): void
    {
        $this->now = $at;
        $this->transition(ShipmentState::Delivered, 'provider', 'Parcel delivered.', $actorEmail);
        $this->deliveredAt = $at;
        $this->updatedAt = $at;
    }

    public function markFailed(SanitizedFailure $failure, \DateTimeImmutable $at, ?string $actorEmail = null): void
    {
        $this->now = $at;
        $this->transition(ShipmentState::Failed, 'provider', $failure->code(), $actorEmail);
        $this->storeFailure($failure);
        $this->updatedAt = $at;
    }

    public function markCancelled(string $reason, \DateTimeImmutable $at, ?string $actorEmail = null): void
    {
        $reason = trim($reason);
        if ('' === $reason || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('A shipment cancellation requires a reason.');
        }
        $this->now = $at;
        // A recall is a decision, not a problem. It is deliberately not written into the failure
        // columns: doing so made the admin screen render the reason under a heading called "Last
        // failure". `cancelledAt` plus the event trail say it properly.
        $this->transition(ShipmentState::Cancelled, 'store', $reason, $actorEmail);
        $this->cancelledAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * The carrier refused to recall a parcel that is still here.
     *
     * No state changes: pretending a parcel was recalled when the courier still holds it is how a
     * customer ends up being told their order was cancelled and then receiving it. The refusal is
     * recorded as an event instead, so an operator can see the attempt happened.
     */
    public function recordRefusedCancellation(SanitizedFailure $failure, \DateTimeImmutable $at, ?string $actorEmail = null): void
    {
        $this->now = $at;
        $this->storeFailure($failure);
        $this->record($this->state, 'provider', 'cancel_refused', $actorEmail);
        $this->updatedAt = $at;
    }

    /**
     * A fact the provider contributed that changed no state — a status that said nothing new, a
     * status the adapter could not map, a label that was printed.
     *
     * Recorded rather than dropped, because "we asked and it said the same thing" and "we asked
     * and it said something we do not understand" are both worth being able to show.
     */
    public function recordProviderFact(string $detail, \DateTimeImmutable $at, ?string $actorEmail = null): void
    {
        $this->now = $at;
        $this->record($this->state, 'provider', $detail, $actorEmail);
        $this->updatedAt = $at;
    }

    /**
     * A tracking number that arrived outside a state change — a carrier issues one when it prints
     * the label, which can be well after the parcel was accepted.
     */
    public function assignTrackingNumber(?string $trackingNumber, \DateTimeImmutable $at, ?string $actorEmail = null): void
    {
        $trackingNumber = ShipmentText::tracking($trackingNumber);
        if (null === $trackingNumber || $trackingNumber === $this->trackingNumber) {
            return;
        }
        $this->now = $at;
        $this->trackingNumber = $trackingNumber;
        $this->record($this->state, 'provider', 'Tracking number assigned.', $actorEmail);
        $this->updatedAt = $at;
    }

    /**
     * A reference the carrier issued for a parcel this row already describes, adopted without a
     * state change.
     *
     * Needed because the carrier call happens outside the database transaction: if the row moved
     * while the carrier was deciding, the reference must still land somewhere. Discarding it would
     * leave a real parcel at the carrier that the store has no handle on.
     */
    public function adoptProviderReference(?string $providerReference, ?string $trackingNumber, \DateTimeImmutable $at, ?string $actorEmail = null): void
    {
        $providerReference = ShipmentText::code($providerReference);
        $trackingNumber = ShipmentText::tracking($trackingNumber);
        $changed = false;
        if (null !== $providerReference && $providerReference !== $this->providerReference) {
            $this->providerReference = $providerReference;
            $changed = true;
        }
        if (null !== $trackingNumber && $trackingNumber !== $this->trackingNumber) {
            $this->trackingNumber = $trackingNumber;
            $changed = true;
        }
        if (!$changed) {
            return;
        }
        $this->now = $at;
        $this->record($this->state, 'provider', 'Carrier reference recorded for a parcel already tracked.', $actorEmail);
        $this->updatedAt = $at;
    }

    private function applyTrackingNumber(?string $trackingNumber): void
    {
        $trackingNumber = ShipmentText::tracking($trackingNumber);
        if (null !== $trackingNumber) {
            $this->trackingNumber = $trackingNumber;
        }
    }

    private function storeFailure(SanitizedFailure $failure): void
    {
        $this->failureCode = $failure->code();
        $this->failureMessage = '' === $failure->message() ? null : $failure->message();
    }

    private function clearFailure(): void
    {
        $this->failureCode = null;
        $this->failureMessage = null;
    }

    private function transition(ShipmentState $next, string $source, ?string $detail, ?string $actorEmail = null): void
    {
        (new ShipmentStateMachine())->assertTransition($this->state, $next);
        $this->state = $next;
        // A shipment that left the failure state has left the failure; leaving the old code behind
        // would have the admin screen reporting a problem the store already recovered from.
        if (ShipmentState::Failed !== $next) {
            $this->clearFailure();
        }
        $this->record($next, $source, $detail, $actorEmail);
    }

    private function record(ShipmentState $toState, string $source, ?string $detail, ?string $actorEmail = null): void
    {
        $this->events->add(new ShipmentEvent($this, $toState, $source, $detail, $this->now, $actorEmail));
    }

    private static function required(string $value, string $message): string
    {
        $value = trim($value);
        if ('' === $value) {
            throw new \InvalidArgumentException($message);
        }

        return $value;
    }
}
