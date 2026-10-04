<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Commerce\Shipment;
use App\Message\CreateShipment;
use App\Module\Shipping\ShipmentOrchestrator;
use App\Module\Shipping\ShipmentProviderRetryable;
use App\Repository\Commerce\ShipmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Hands one parcel to its carrier, at most once.
 *
 * The message carries a shipment id and nothing else, and the handler may be invoked any number of
 * times for the same message — a retried delivery, a requeued failed message, an operator pressing
 * the button again. Every one of those must leave exactly one parcel at the carrier and one row
 * locally, so the two guards are:
 *
 * - the provider is asked only while the shipment still awaits creation, and is given an
 *   idempotency key derived from the order, so even a call that slips through twice is answered
 *   with the same parcel;
 * - the outcome is applied to a freshly locked row, so a status callback that landed first is
 *   respected rather than overwritten.
 *
 * A transient carrier failure is recorded and re-raised, which leaves the shipment in a state a
 * retry is still allowed from. A permanent refusal is recorded and not re-raised: retrying a bad
 * address only spends the carrier's patience.
 */
#[AsMessageHandler]
final readonly class CreateShipmentHandler
{
    public function __construct(
        private ShipmentRepository $shipments,
        private ShipmentOrchestrator $orchestrator,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateShipment $message): void
    {
        $shipment = $this->shipments->find($message->shipmentId);
        // No row, or a parcel the carrier has already been told about: a replay, and nothing to do.
        if (!$shipment instanceof Shipment || !$shipment->awaitsProviderCreation()) {
            return;
        }
        if (!$this->orchestrator->requiresProviderHandshake($shipment)) {
            // Hand delivery is fulfilled by the operator, not by a worker. Reaching here means the
            // message was routed for a shipment that never needed one.
            throw new UnrecoverableMessageHandlingException(sprintf('Shipment %s does not need a carrier.', $shipment->orderNumber()));
        }

        $failure = null;
        $this->entityManager->wrapInTransaction(function () use ($shipment, $message, &$failure): void {
            // Keep the order lock through the carrier call: cancellation must not race a
            // real parcel creation that was formerly started before any row was locked.
            $this->entityManager->refresh($shipment->order(), \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $locked = $this->shipments->findForUpdate($message->shipmentId);
            if (!$locked instanceof Shipment) {
                // The order went away while the carrier was deciding. The parcel still exists at the
                // carrier, and this store can no longer do anything about it; the admin report of
                // orphaned messages is where that has to be resolved.
                return;
            }
            if (\App\Module\Order\OrderState::Confirmed !== $locked->order()->state()
                || !$locked->awaitsProviderCreation()) {
                return;
            }
            $outcome = $this->orchestrator->providerFor($locked)->create($this->orchestrator->creationInstructionFor($locked));
            $failure = $outcome->failure();
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            if (null === $failure) {
                $locked->markReady($outcome->providerReference(), $outcome->trackingNumber(), $now);
            } else {
                // Recorded either way. A shipment the carrier could not take has to be visible to
                // an operator, whether it will be retried or not.
                $locked->markFailed($failure, $now);
            }
            $this->shipments->save($locked);
            $this->entityManager->flush();
        });

        if (null !== $failure && $failure->isRetryable()) {
            throw new ShipmentProviderRetryable(sprintf('The carrier could not take shipment %d yet: %s', $message->shipmentId, $failure->code()));
        }
    }
}
