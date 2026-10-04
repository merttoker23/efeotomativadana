<?php

declare(strict_types=1);

namespace App\Module\Shipping;

use App\Entity\Commerce\Shipment;
use App\Message\CreateShipment;
use App\Module\Audit\AuditAction;
use App\Module\Audit\AuditLogger;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderNotFound;
use App\Module\Order\OrderRepositoryInterface;
use App\Module\Notification\Event\ShipmentMoved;
use App\Module\Payment\SanitizedFailure;
use App\Module\Shipping\Gateway\ShipmentAddress;
use App\Module\Shipping\Gateway\ShipmentCancellationInstruction;
use App\Module\Shipping\Gateway\ShipmentCreationInstruction;
use App\Module\Shipping\Gateway\ShipmentItem;
use App\Module\Shipping\Gateway\ShipmentLabel;
use App\Module\Shipping\Gateway\ShipmentLabelRequest;
use App\Module\Shipping\Gateway\ShipmentStatusReport;
use App\Module\Shipping\Gateway\ShipmentStatusRequest;
use App\Module\Shipping\Gateway\ShippingProviderInterface;
use App\Repository\Commerce\ShipmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Every action a shipment can take, and the only place the carrier boundary is crossed.
 *
 * Three properties this class is responsible for:
 *
 * - **Creation is idempotent.** Calling it twice returns the same row. The unique index on
 *   `order_id` is the backstop, but returning the existing shipment is what makes a double-clicked
 *   button feel like nothing happened rather than like an error.
 * - **Provider work is queued, not inlined.** A carrier call can be slow, can fail and must be
 *   retried, so it goes through Messenger. Hand delivery never does, which is what keeps normal
 *   commerce working with no carrier configured and no worker running.
 * - **Nothing here moves the order.** The two aggregates are related and never conflated: a parcel
 *   can fail at the carrier while the order stands confirmed, and a delivered parcel does not
 *   complete an order.
 *
 * Every state change is additionally written to the cross-cutting audit trail. `ShipmentEvent`
 * records what the parcel became and who said so; the audit row records the action as a discrete
 * named fact, so "this operator recalled this parcel on this day" is one query rather than a
 * reading of the event log.
 */
final readonly class ShipmentOrchestrator
{
    public function __construct(
        private ShippingMethodRegistry $methods,
        private ShippingProviderRegistry $providers,
        private ShipmentRepository $shipments,
        private OrderRepositoryInterface $orders,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private MessageBusInterface $bus,
        private EventDispatcherInterface $events,
        private AuditLogger $audit,
    ) {
    }

    /**
     * The shipment for an order, creating it the first time.
     *
     * The order is locked first, so two operators pressing the button at the same moment cannot
     * both decide there is no shipment yet. Whichever loses the race is handed the winner's row.
     */
    public function createForOrder(string $orderNumber, string $actorEmail): Shipment
    {
        $shipment = $this->entityManager->wrapInTransaction(function () use ($orderNumber, $actorEmail): Shipment {
            $order = $this->orders->findOneByNumberForUpdate($orderNumber)
                ?? throw new OrderNotFound(sprintf('Order %s was not found.', $orderNumber));

            // The existing row wins before anything is resolved. PHASE 17 will rename and retire
            // carrier methods, and a second press on an order whose method no longer resolves must
            // still hand back the parcel that is already recorded rather than fail.
            $existing = $this->shipments->findOneForOrder($order);
            if (null !== $existing) {
                return $existing;
            }

            $method = $this->methods->select($order->shippingOptionKey());
            $shipment = Shipment::start($order, $method->key(), $method->label(), $method->providerKey(), $this->now(), $actorEmail);
            $this->shipments->save($shipment);
            $this->audit->record(AuditAction::ShipmentCreated, $order->orderNumber(), [
                'method_key' => $method->key(),
                'provider' => $method->providerKey(),
                'actor' => $actorEmail,
            ]);
            $this->entityManager->flush();

            return $shipment;
        });

        // Queued after the transaction commits, so a worker can never pick the message up before
        // the row it points at is visible. A second call re-queues a shipment that is still
        // waiting, which is also how an operator retries a dispatch that was lost.
        if ($shipment->awaitsProviderCreation() && $this->requiresProviderHandshake($shipment)) {
            $this->bus->dispatch(new CreateShipment((int) $shipment->id()));
        }

        return $shipment;
    }

    /**
     * The store hands the parcel to its own driver.
     *
     * Refused for a carrier-backed shipment: recording a handover the carrier never accepted would
     * leave a parcel the store believes is moving and the carrier has never heard of.
     */
    public function handOver(Shipment $shipment, ?string $trackingNumber, string $actorEmail): Shipment
    {
        $this->assertHandFulfilled($shipment, 'handed over by hand');

        return $this->apply($shipment, function (Shipment $locked) use ($trackingNumber, $actorEmail): void {
            $locked->markReady(null, $trackingNumber, $this->now(), $actorEmail);
        }, AuditAction::ShipmentHandedOver, ['tracking_number' => $trackingNumber, 'actor' => $actorEmail]);
    }

    /**
     * Refuses any state change that would put the store's word ahead of the carrier's.
     *
     * A carrier-backed parcel's state comes from the carrier, through {@see refreshStatus()}. An
     * operator marking one "delivered" by hand is how a customer is told their parcel arrived while
     * the courier is still holding it — the mirror image of a parcel cancelled but then delivered.
     */
    private function assertHandFulfilled(Shipment $shipment, string $action): void
    {
        if (!$shipment->isHandFulfilledByStore()) {
            throw new \DomainException(sprintf(
                'Shipment %s is fulfilled by its carrier and cannot be %s; refresh its status instead.',
                $shipment->orderNumber(),
                $action,
            ));
        }
    }

    public function markInTransit(Shipment $shipment, string $actorEmail): Shipment
    {
        $this->assertHandFulfilled($shipment, 'moved to in transit');

        return $this->apply($shipment, function (Shipment $locked) use ($actorEmail): void {
            $locked->markInTransit($this->now(), $actorEmail);
        }, AuditAction::ShipmentMarkedInTransit, ['actor' => $actorEmail]);
    }

    public function markDelivered(Shipment $shipment, string $actorEmail): Shipment
    {
        $this->assertHandFulfilled($shipment, 'marked delivered');

        return $this->apply($shipment, function (Shipment $locked) use ($actorEmail): void {
            $locked->markDelivered($this->now(), $actorEmail);
        }, AuditAction::ShipmentMarkedDelivered, ['actor' => $actorEmail]);
    }

    /**
     * Recall a parcel that has not left the building.
     *
     * The carrier is asked first. A refusal leaves the shipment exactly where it was and raises
     * {@see ShipmentCancellationRefused}, because a parcel the courier still holds is not a
     * cancelled parcel.
     */
    public function cancel(Shipment $shipment, string $reason, string $actorEmail): Shipment
    {
        $refused = null;
        $cancelled = $this->entityManager->wrapInTransaction(function () use ($shipment, $reason, $actorEmail, &$refused): Shipment {
            $this->entityManager->refresh($shipment->order(), \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $locked = $this->shipments->findForUpdate((int) $shipment->id())
                ?? throw new ShipmentNotFound('Shipment was not found.');
            if (!$locked->state()->isCancellable()) {
                throw new \DomainException('This shipment cannot be cancelled.');
            }
            try {
                // Resolve and recall the carrier reference under the creation lock too.
                return $this->cancelLocked($locked, $reason, $actorEmail);
            } catch (ShipmentCancellationRefused $failure) {
                $refused = $failure;

                return $locked;
            }
        });
        if (null !== $refused) {
            throw $refused;
        }

        return $cancelled;
    }

    private function cancelLocked(Shipment $shipment, string $reason, string $actorEmail): Shipment
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new \InvalidArgumentException('A shipment cancellation requires a reason.');
        }

        $provider = null !== $shipment->providerReference() ? $this->providerFor($shipment) : null;
        if (null !== $provider) {
            $outcome = $provider->cancel(new ShipmentCancellationInstruction(
                $shipment->providerKey(),
                (string) $shipment->providerReference(),
                'cancel-'.$shipment->idempotencyKey(),
                $reason,
            ));
            if (!$outcome->isCancelled()) {
                $failure = $outcome->failure();
                $this->apply(
                    $shipment,
                    function (Shipment $locked) use ($failure, $actorEmail): void {
                        $locked->recordRefusedCancellation($failure ?? SanitizedFailure::fromProvider('cancel_refused', null, null), $this->now(), $actorEmail);
                    },
                    AuditAction::ShipmentCancelled,
                    ['outcome' => 'refused', 'reason' => $reason, 'actor' => $actorEmail],
                );

                throw new ShipmentCancellationRefused(sprintf(
                    'The carrier refused to recall shipment %s: %s',
                    $shipment->orderNumber(),
                    $failure?->code() ?? 'cancel_refused',
                ));
            }
        }

        return $this->apply($shipment, function (Shipment $locked) use ($reason, $actorEmail): void {
            $locked->markCancelled($reason, $this->now(), $actorEmail);
        }, AuditAction::ShipmentCancelled, ['outcome' => 'cancelled', 'reason' => $reason, 'actor' => $actorEmail]);
    }

    /**
     * Ask the carrier where the parcel is and record whatever it says.
     *
     * Unrecognised wording and unchanged answers are recorded rather than acted on, and a report
     * that would move an already-delivered parcel is rejected by the aggregate's own state machine
     * instead of being quietly dropped here.
     */
    public function refreshStatus(Shipment $shipment, string $actorEmail): Shipment
    {
        $report = $this->providerForAcceptedParcel($shipment)->status($this->statusRequestFor($shipment));
        $state = $report->state();
        $trackingNumber = $report->trackingNumber();
        $now = $this->now();

        return $this->apply($shipment, function (Shipment $locked) use ($state, $trackingNumber, $report, $now, $actorEmail): void {
            // The carrier's own wording is recorded first, whether or not it changed anything, so
            // the trail reads as it happened: the carrier said this, and then the parcel moved.
            $locked->recordProviderFact($report->providerStatus(), $now, $actorEmail);

            if (null === $state || $locked->state() === $state) {
                // Unmapped wording, or a repeat of what we already know. Recording the answer and
                // stopping is what makes a webhook replay free of consequences.
                $locked->assignTrackingNumber($trackingNumber, $now, $actorEmail);

                return;
            }

            match ($state) {
                ShipmentState::Ready => $locked->markReady($locked->providerReference(), $trackingNumber, $now, $actorEmail),
                ShipmentState::InTransit => $this->advance($locked, $trackingNumber, $now, $actorEmail),
                ShipmentState::Delivered => $this->arrive($locked, $trackingNumber, $now, $actorEmail),
                ShipmentState::Failed => $locked->markFailed(SanitizedFailure::fromProvider('provider_reported_failure', $report->providerStatus(), null), $now, $actorEmail),
                ShipmentState::Cancelled => $locked->markCancelled($report->providerStatus(), $now, $actorEmail),
                // A carrier that reported `pending` has told us nothing; the row is already pending.
                ShipmentState::Pending => null,
            };
        }, AuditAction::ShipmentStatusRefreshed, ['actor' => $actorEmail]);
    }

    /**
     * An operator asks for one more attempt at a shipment the carrier refused.
     *
     * The decision is explicit because nothing about the parcel changed: a wrong address will be
     * refused identically, so an automatic retry would only turn one refusal into several.
     */
    public function retryCreation(Shipment $shipment, string $actorEmail): Shipment
    {
        $retried = $this->apply($shipment, function (Shipment $locked) use ($actorEmail): void {
            $locked->retryCreation($this->now(), $actorEmail);
        }, AuditAction::ShipmentCreationRetried, ['actor' => $actorEmail]);

        if ($this->requiresProviderHandshake($retried)) {
            $this->bus->dispatch(new CreateShipment((int) $retried->id()));
        }

        return $retried;
    }

    /**
     * Fetch the carrier's printable label and take its tracking number.
     *
     * Many carriers only issue a tracking number when the label is printed, which can be long
     * after the parcel was accepted. The document URL is deliberately not persisted and not
     * rendered: an untrusted carrier host has no business becoming a clickable target.
     */
    public function requestLabel(Shipment $shipment, string $actorEmail): ?ShipmentLabel
    {
        $provider = $this->providerForAcceptedParcel($shipment);
        $label = $provider->label(new ShipmentLabelRequest($shipment->providerKey(), (string) $shipment->providerReference()));
        if (null === $label) {
            return null;
        }

        $trackingNumber = $label->trackingNumber();
        $now = $this->now();

        $this->apply($shipment, function (Shipment $locked) use ($trackingNumber, $now, $actorEmail): void {
            $locked->assignTrackingNumber($trackingNumber, $now, $actorEmail);
            $locked->recordProviderFact(sprintf('Label issued (%s).', $locked->providerKey()), $now, $actorEmail);
        }, AuditAction::ShipmentLabelRequested, ['tracking_number' => $trackingNumber, 'actor' => $actorEmail]);

        return $label;
    }

    /**
     * The carrier adapter that fulfils a shipment, or a refusal explaining why there is none.
     *
     * Resolution uses the shipment's own recorded provider rather than the `shipping.provider`
     * setting, for the same reason payments do: switching the store's carrier must not move an
     * order that is already with a different one.
     */
    public function providerFor(Shipment $shipment): ShippingProviderInterface
    {
        if (!$this->requiresProviderHandshake($shipment)) {
            throw new \DomainException(sprintf('Shipment %s is fulfilled by the store and carries no carrier reference.', $shipment->orderNumber()));
        }

        return $this->providers->resolveOrFail($shipment->providerKey());
    }

    /**
     * The same adapter, for the calls that address a parcel the carrier has already accepted.
     *
     * Separate from {@see providerFor()} because `create` is the one call that legitimately runs
     * before a reference exists — it is the call that produces one.
     */
    public function providerForAcceptedParcel(Shipment $shipment): ShippingProviderInterface
    {
        $provider = $this->providerFor($shipment);
        if (null === $shipment->providerReference()) {
            throw new \DomainException(sprintf('Shipment %s has not been accepted by a carrier yet.', $shipment->orderNumber()));
        }

        return $provider;
    }

    /** Whether fulfilling this shipment crosses the carrier boundary at all. */
    public function requiresProviderHandshake(Shipment $shipment): bool
    {
        return !$shipment->isHandFulfilledByStore();
    }

    /**
     * Everything a carrier is told, built from the order's sealed snapshot.
     *
     * Deliberately read-only: the order cannot be reached through this object, so an adapter
     * physically cannot mutate it, and a message that waited in the queue for an hour still
     * describes the order as it was placed.
     */
    public function creationInstructionFor(Shipment $shipment): ShipmentCreationInstruction
    {
        $order = $shipment->order();
        $address = $order->address(OrderAddressRole::Shipping)
            ?? throw new \DomainException(sprintf('Order %s has no shipping address to dispatch.', $order->orderNumber()));

        $items = [];
        foreach ($order->items() as $item) {
            $items[] = new ShipmentItem($item->sku(), $item->productName(), $item->quantity());
        }

        return new ShipmentCreationInstruction(
            $shipment->providerKey(),
            $shipment->idempotencyKey(),
            $order->orderNumber(),
            $shipment->methodKey(),
            $shipment->methodLabel(),
            new ShipmentAddress(
                $address->recipientName(),
                $address->phone(),
                $address->addressLine1(),
                $address->addressLine2(),
                $address->district(),
                $address->city(),
                $address->postalCode(),
                $address->countryCode(),
            ),
            $items,
            $order->grandTotal(),
        );
    }

    public function statusRequestFor(Shipment $shipment): ShipmentStatusRequest
    {
        return new ShipmentStatusRequest($shipment->providerKey(), (string) $shipment->providerReference());
    }

    /**
     * Re-reads the shipment under a row lock and applies one change inside a transaction.
     *
     * The controller's copy is only used to say *which* shipment; the decision is always made from
     * a freshly locked row, so a carrier callback that arrived first wins rather than being
     * overwritten by a stale screen.
     *
     * The audit row is written inside the same transaction as the change it describes, and before
     * the flush, so a trail cannot come to contain a transition that then rolled back.
     *
     * @param callable(Shipment): void $change
     * @param array<string, mixed>    $payload
     */
    private function apply(Shipment $shipment, callable $change, ?AuditAction $action = null, array $payload = []): Shipment
    {
        $id = (int) $shipment->id();

        $applied = $this->entityManager->wrapInTransaction(function () use ($shipment, $id, $change, $action, $payload): Shipment {
            $this->entityManager->refresh($shipment->order(), \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $locked = $this->shipments->findForUpdate($id)
                ?? throw new ShipmentNotFound(sprintf('Shipment %d was not found.', $id));
            if (\App\Module\Order\OrderState::Cancelled === $locked->order()->state()
                && AuditAction::ShipmentCancelled !== $action) {
                throw new \DomainException('A cancelled order cannot advance its shipment.');
            }
            $change($locked);
            $this->shipments->save($locked);
            if (null !== $action) {
                $this->audit->record($action, $locked->orderNumber(), $payload + [
                    'method_key' => $locked->methodKey(),
                    'provider' => $locked->providerKey(),
                    'to_state' => $locked->state()->value,
                ]);
            }
            $this->entityManager->flush();

            return $locked;
        });

        // Raised after the commit, so a subscriber — currently the notification that tells the
        // customer their parcel left — can never fail the parcel's own state change. It is raised
        // for every change here, and the subscriber decides what is worth an email.
        $this->events->dispatch(new ShipmentMoved($applied));

        return $applied;
    }

    private function advance(Shipment $shipment, ?string $trackingNumber, \DateTimeImmutable $now, ?string $actorEmail = null): void
    {
        $shipment->assignTrackingNumber($trackingNumber, $now, $actorEmail);
        $shipment->markInTransit($now, $actorEmail);
    }

    private function arrive(Shipment $shipment, ?string $trackingNumber, \DateTimeImmutable $now, ?string $actorEmail = null): void
    {
        $shipment->assignTrackingNumber($trackingNumber, $now, $actorEmail);
        $shipment->markDelivered($now, $actorEmail);
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
