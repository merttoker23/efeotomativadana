<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shipping;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Shipment;
use App\Entity\Customer\CustomerUser;
use App\Message\CreateShipment;
use App\MessageHandler\CreateShipmentHandler;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderState;
use App\Module\Order\OrderRepositoryInterface;
use App\Module\Shipping\FakeShippingProvider;
use App\Module\Shipping\Gateway\ShipmentCancellationOutcome;
use App\Module\Shipping\Gateway\ShipmentCreationOutcome;
use App\Module\Shipping\Gateway\ShipmentLabel;
use App\Module\Shipping\Gateway\ShipmentStatusReport;
use App\Module\Shipping\ShipmentCancellationRefused;
use App\Module\Shipping\ShipmentOrchestrator;
use App\Module\Shipping\ShipmentProviderRetryable;
use App\Module\Shipping\ShipmentState;
use App\Repository\Commerce\ShipmentRepository;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ShipmentOrchestrationTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private ShipmentRepository $shipments;
    private ShipmentOrchestrator $orchestrator;
    private CreateShipmentHandler $handler;
    private FakeShippingProvider $provider;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->connection->beginTransaction();
        $this->entityManager = $container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $this->entityManager);
        $shipments = $this->entityManager->getRepository(Shipment::class);
        self::assertInstanceOf(ShipmentRepository::class, $shipments);
        $this->shipments = $shipments;
        $this->orchestrator = $container->get(ShipmentOrchestrator::class);
        self::assertInstanceOf(ShipmentOrchestrator::class, $this->orchestrator);
        $this->handler = $container->get(CreateShipmentHandler::class);
        self::assertInstanceOf(CreateShipmentHandler::class, $this->handler);
        $this->provider = $container->get(FakeShippingProvider::class);
        self::assertInstanceOf(FakeShippingProvider::class, $this->provider);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------- normal commerce, no carrier

    public function testAnOrderIsShippableWithNoCarrierConfiguredAtAll(): void
    {
        // The plan's core requirement: the store keeps working before a carrier is ever chosen.
        // `shipping.provider` is unset in this environment, and nothing here may need it.
        self::assertNull(self::getContainer()->get(\App\Module\Settings\StoreConfiguration::class)->shippingProvider());

        $order = $this->confirmedOrder('local@example.com');

        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');

        self::assertSame(ShipmentState::Pending, $shipment->state());
        self::assertSame('local_standard', $shipment->methodKey());
        self::assertSame('manual', $shipment->providerKey());
    }

    public function testNormalCommerceFulfilsAnOrderByHandFromStartToFinish(): void
    {
        $queuedBefore = $this->queuedMessages();
        $order = $this->confirmedOrder('hand@example.com');
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');

        $shipment = $this->orchestrator->handOver($shipment, 'VAN-ADI-2026-09-28-1', 'admin@example.com');
        self::assertSame(ShipmentState::Ready, $shipment->state());
        self::assertSame('VAN-ADI-2026-09-28-1', $shipment->trackingNumber());
        self::assertNull($shipment->providerReference());

        $shipment = $this->orchestrator->markInTransit($shipment, 'admin@example.com');
        self::assertSame(ShipmentState::InTransit, $shipment->state());

        $shipment = $this->orchestrator->markDelivered($shipment, 'admin@example.com');
        self::assertSame(ShipmentState::Delivered, $shipment->state());
        self::assertNotNull($shipment->deliveredAt());

        self::assertSame(0, $this->provider->createRequestCount(), 'Hand delivery must never call a carrier.');
        self::assertSame($queuedBefore, $this->queuedMessages(), 'Hand delivery must never queue provider work.');
    }

    public function testALocalShipmentQueuesNoProviderWork(): void
    {
        $queuedBefore = $this->queuedMessages();
        $order = $this->confirmedOrder('local-queue@example.com');

        $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');

        self::assertSame($queuedBefore, $this->queuedMessages());
    }

    public function testAProviderBackedShipmentQueuesExactlyOneCarrierCall(): void
    {
        $queuedBefore = $this->queuedMessages();
        $order = $this->confirmedOrder('queued@example.com', 'carrier_express');

        $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');

        self::assertSame($queuedBefore + 1, $this->queuedMessages());
        self::assertSame(0, $this->provider->createRequestCount(), 'Queuing work must not call the carrier inline.');
    }

    // ---------------------------------------------------------------- replay safety

    public function testCreatingAShipmentTwiceReturnsTheSameRowRatherThanASecondParcel(): void
    {
        $order = $this->confirmedOrder('twice@example.com');

        $first = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $second = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');

        self::assertSame($first->id(), $second->id());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_shipment'));
    }

    public function testAReplayedShipmentCreateMessageDoesNotCreateASecondParcelAnywhere(): void
    {
        $order = $this->confirmedOrder('replay@example.com', 'carrier_express');
        $this->provider->queueCreation(ShipmentCreationOutcome::accepted('FAKE-SHIP-1', 'FAKE-TRK-1'));
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $message = new CreateShipment($shipment->id());

        $this->handler($message);
        $this->handler($message);
        $this->handler($message);

        self::assertSame(1, $this->provider->createRequestCount(), 'A replayed message must not reach the carrier a second time at all.');
        self::assertSame(1, $this->provider->acceptedCreateCount(), 'Exactly one parcel may exist at the carrier.');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_shipment'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_shipment_event WHERE to_state = ?', [ShipmentState::Ready->value]));
        $reloaded = $this->reload($shipment->id());
        self::assertSame(ShipmentState::Ready, $reloaded->state());
        self::assertSame('FAKE-SHIP-1', $reloaded->providerReference());
        self::assertSame('FAKE-TRK-1', $reloaded->trackingNumber());
    }

    public function testAReplayAfterTheCarrierRefusedPermanentlyAsksNobodyAndChangesNothing(): void
    {
        $order = $this->confirmedOrder('permanent@example.com', 'carrier_express');
        $this->provider->queueCreation(ShipmentCreationOutcome::refused(\App\Module\Payment\SanitizedFailure::fromProvider('address_invalid', 'no such district', null)));
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $message = new CreateShipment($shipment->id());

        $this->handler($message);
        $this->handler($message);

        self::assertSame(1, $this->provider->createRequestCount());
        self::assertSame(ShipmentState::Failed, $this->reload($shipment->id())->state());
    }

    public function testATransientCarrierFailureIsRetriedOnTheSameRowAndCanStillSucceed(): void
    {
        $order = $this->confirmedOrder('transient@example.com', 'carrier_express');
        $this->provider->queueCreation(ShipmentCreationOutcome::refused(\App\Module\Payment\SanitizedFailure::fromProvider('service_unavailable', 'carrier 500', null)));
        $this->provider->queueCreation(ShipmentCreationOutcome::accepted('FAKE-SHIP-RETRY', 'FAKE-TRK-RETRY'));
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $message = new CreateShipment($shipment->id());

        try {
            $this->handler($message);
            self::fail('A transient carrier failure must be retried, not swallowed.');
        } catch (ShipmentProviderRetryable $exception) {
            self::assertStringContainsString('service_unavailable', $exception->getMessage());
        }

        // The failure is visible to an operator rather than leaving a pending parcel with no story.
        $failed = $this->reload($shipment->id());
        self::assertSame(ShipmentState::Failed, $failed->state());
        self::assertSame('service_unavailable', $failed->failure()?->code());

        $this->handler($message);

        self::assertSame(2, $this->provider->createRequestCount());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_shipment'));
        $recovered = $this->reload($shipment->id());
        self::assertSame(ShipmentState::Ready, $recovered->state());
        self::assertSame('FAKE-SHIP-RETRY', $recovered->providerReference());
        self::assertNull($recovered->failure());
    }

    public function testTheCarrierIsHandedExactlyWhatTheOrderSealed(): void
    {
        $order = $this->confirmedOrder('instruction@example.com', 'carrier_express');
        $this->provider->queueCreation(ShipmentCreationOutcome::accepted('FAKE-SHIP-2', 'FAKE-TRK-2'));
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');

        $this->handler(new CreateShipment($shipment->id()));

        $seen = $this->provider->lastCreationInstruction();
        self::assertNotNull($seen);
        self::assertSame('fake', $seen->providerKey());
        self::assertSame($order->orderNumber(), $seen->orderNumber());
        self::assertSame($shipment->idempotencyKey(), $seen->idempotencyKey());
        self::assertSame('Efe Yılmaz', $seen->address()->recipientName());
        self::assertSame('Atatürk Cad. 1', $seen->address()->addressLine1());
        self::assertSame('Seyhan', $seen->address()->district());
        self::assertSame('Adana', $seen->address()->city());
        self::assertSame('TR', $seen->address()->countryCode());
        self::assertSame($order->grandTotal()->minorAmount(), $seen->declaredValue()->minorAmount());
        self::assertCount(1, $seen->items());
        self::assertSame(2, $seen->items()[0]->quantity());
        self::assertSame('BRK-1', $seen->items()[0]->sku());
    }

    // ---------------------------------------------------------------- status, labels, cancellation

    public function testACarrierStatusMovesTheParcelAndKeepsTheCarriersOwnWording(): void
    {
        $shipment = $this->readyShipment('status@example.com');
        $this->provider->queueStatus(ShipmentStatusReport::reporting(ShipmentState::InTransit, 'FAKE-TRK-NEW', 'Yola çıktı'));

        $updated = $this->orchestrator->refreshStatus($shipment, 'admin@example.com');

        self::assertSame(ShipmentState::InTransit, $updated->state());
        self::assertSame('FAKE-TRK-NEW', $updated->trackingNumber());
        // The carrier's wording is filed before the move it caused, so the trail reads as it
        // happened and the transition detail is still the store's own.
        $details = array_map(static fn ($event): ?string => $event->detail(), $updated->events());
        self::assertContains('Yola çıktı', $details);
        self::assertSame('Parcel is on the road.', $updated->events()[array_key_last($updated->events())]->detail());
    }

    public function testARepeatedStatusReportChangesNothingTheSecondTime(): void
    {
        $shipment = $this->readyShipment('repeat-status@example.com');
        $this->provider->queueStatus(ShipmentStatusReport::reporting(ShipmentState::InTransit, 'FAKE-TRK-1', 'Yola çıktı'));
        $this->orchestrator->refreshStatus($shipment, 'admin@example.com');
        $before = $this->reload($shipment->id())->state();

        $this->provider->queueStatus(ShipmentStatusReport::reporting(ShipmentState::InTransit, 'FAKE-TRK-1', 'Yola çıktı'));
        $again = $this->orchestrator->refreshStatus($this->reload($shipment->id()), 'admin@example.com');

        self::assertSame($before, $again->state());
    }

    public function testACarrierStatusTheAdapterCannotMapIsRecordedButActsOnNothing(): void
    {
        $shipment = $this->readyShipment('unmapped@example.com');
        $this->provider->queueStatus(ShipmentStatusReport::unrecognised('KOD-???'));

        $unchanged = $this->orchestrator->refreshStatus($shipment, 'admin@example.com');

        self::assertSame(ShipmentState::Ready, $unchanged->state());
        self::assertSame('KOD-???', $unchanged->events()[array_key_last($unchanged->events())]->detail());
    }

    public function testAnOperatorCanAskForOneMoreAttemptAfterAPermanentRefusal(): void
    {
        $order = $this->confirmedOrder('operator-retry@example.com', 'carrier_express');
        $this->provider->queueCreation(ShipmentCreationOutcome::refused(\App\Module\Payment\SanitizedFailure::fromProvider('address_invalid', 'no such district', null)));
        $this->provider->queueCreation(ShipmentCreationOutcome::accepted('FAKE-SHIP-SECOND', 'FAKE-TRK-SECOND'));
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $this->handler(new CreateShipment($shipment->id()));
        self::assertSame(ShipmentState::Failed, $this->reload($shipment->id())->state());
        $queuedBefore = $this->queuedMessages();

        $retried = $this->orchestrator->retryCreation($this->reload($shipment->id()), 'admin@example.com');

        self::assertSame(ShipmentState::Pending, $retried->state());
        self::assertSame($queuedBefore + 1, $this->queuedMessages(), 'A retry queues exactly one further carrier call.');
        $this->handler(new CreateShipment($shipment->id()));

        self::assertSame(2, $this->provider->createRequestCount());
        $recovered = $this->reload($shipment->id());
        self::assertSame(ShipmentState::Ready, $recovered->state());
        self::assertSame('FAKE-SHIP-SECOND', $recovered->providerReference());
        self::assertNull($recovered->failure());
    }

    public function testAShipmentThatHasNotFailedCannotBeRetried(): void
    {
        $order = $this->confirmedOrder('retry-not-failed@example.com', 'carrier_express');
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('A pending shipment cannot be retried.');

        $this->orchestrator->retryCreation($shipment, 'admin@example.com');
    }

    public function testAStatusThatWouldUndeliverAParcelIsRejected(): void
    {
        // The carrier is the only thing that may move a carrier parcel, so this is also the only
        // route by which it becomes delivered — and the only route by which the scenario is real.
        $shipment = $this->readyShipment('undeliver@example.com');
        $this->provider->queueStatus(ShipmentStatusReport::reporting(ShipmentState::Delivered, null, 'Teslim edildi'));
        $delivered = $this->orchestrator->refreshStatus($shipment, 'admin@example.com');
        self::assertSame(ShipmentState::Delivered, $delivered->state());

        $this->provider->queueStatus(ShipmentStatusReport::reporting(ShipmentState::InTransit, null, 'Geri alındı'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Shipment cannot transition from delivered to in_transit.');

        $this->orchestrator->refreshStatus($delivered, 'admin@example.com');
    }

    public function testALabelSuppliesTheTrackingNumberACarrierOnlyIssuesAtPrintTime(): void
    {
        $shipment = Shipment::start($this->confirmedOrder('label@example.com', 'carrier_express'), 'carrier_express', 'Ekspres', 'fake', new \DateTimeImmutable());
        $this->shipments->save($shipment);
        $this->entityManager->flush();
        $shipment->markReady('FAKE-SHIP-L', null, new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->provider->queueLabel(new ShipmentLabel('FAKE-TRK-LABEL', 'https://carrier.test/label/L.pdf'));

        $this->orchestrator->requestLabel($this->reload($shipment->id()), 'admin@example.com');

        $reloaded = $this->reload($shipment->id());
        self::assertSame('FAKE-TRK-LABEL', $reloaded->trackingNumber());
        // The document URL is an untrusted host. Nothing this phase renders may turn it into a
        // link, so it must not reach the database either.
        $stored = (string) $this->connection->fetchOne('SELECT GROUP_CONCAT(detail) FROM commerce_shipment_event WHERE shipment_id = ?', [$shipment->id()]);
        self::assertStringNotContainsString('carrier.test', $stored);
    }

    public function testACarrierWithoutLabelsIsNotAnError(): void
    {
        $shipment = $this->readyShipment('no-label@example.com');

        self::assertNull($this->orchestrator->requestLabel($shipment, 'admin@example.com'));
    }

    public function testALocalShipmentCannotBeAskedForACarrierLabel(): void
    {
        $order = $this->confirmedOrder('label-local@example.com');
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $shipment = $this->orchestrator->handOver($shipment, 'VAN-1', 'admin@example.com');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('carries no carrier reference');

        $this->orchestrator->requestLabel($shipment, 'admin@example.com');
    }

    public function testALocallyCancelledShipmentNeedsNoCarrierAndKeepsItsReason(): void
    {
        $order = $this->confirmedOrder('cancel-local@example.com');
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $shipment = $this->orchestrator->handOver($shipment, 'VAN-2', 'admin@example.com');

        $cancelled = $this->orchestrator->cancel($shipment, 'Müşteri siparişi iptal etti', 'admin@example.com');

        self::assertSame(ShipmentState::Cancelled, $cancelled->state());
        self::assertNotNull($cancelled->cancelledAt());
        self::assertSame(0, $this->provider->createRequestCount());
        self::assertSame('Müşteri siparişi iptal etti', $cancelled->events()[array_key_last($cancelled->events())]->detail());
    }

    public function testACarrierRefusalToRecallLeavesTheParcelExactlyWhereItIs(): void
    {
        $shipment = $this->readyShipment('recall-refused@example.com');
        $this->provider->queueCancellation(ShipmentCancellationOutcome::refused(\App\Module\Payment\SanitizedFailure::fromProvider('already_dispatched', 'too late', null)));

        try {
            $this->orchestrator->cancel($shipment, 'Yanlış adres', 'admin@example.com');
            self::fail('A refused recall must not report success.');
        } catch (ShipmentCancellationRefused) {
        }

        $reloaded = $this->reload($shipment->id());
        self::assertSame(ShipmentState::Ready, $reloaded->state(), 'The courier still holds this parcel.');
        self::assertSame('already_dispatched', $reloaded->failure()?->code());
        self::assertSame('cancel_refused', $reloaded->events()[array_key_last($reloaded->events())]->detail());
    }

    public function testACarrierBackedRecallAsksTheCarrierBeforeTheLocalRowIsCancelled(): void
    {
        $shipment = $this->readyShipment('recall-ok@example.com');
        $this->provider->queueCancellation(ShipmentCancellationOutcome::cancelled());

        $cancelled = $this->orchestrator->cancel($shipment, 'Yanlış adres', 'admin@example.com');

        self::assertSame(ShipmentState::Cancelled, $cancelled->state());
    }

    public function testAParcelOnTheRoadCannotBeCancelledByHand(): void
    {
        $order = $this->confirmedOrder('in-transit-cancel@example.com');
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $shipment = $this->orchestrator->handOver($shipment, 'VAN-3', 'admin@example.com');
        $shipment = $this->orchestrator->markInTransit($shipment, 'admin@example.com');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Shipment cannot transition from in_transit to cancelled.');

        $this->orchestrator->cancel($shipment, 'Yanlış adres', 'admin@example.com');
    }

    public function testACancellationNeedsAReason(): void
    {
        $order = $this->confirmedOrder('cancel-reason@example.com');
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');

        $this->expectException(\InvalidArgumentException::class);

        $this->orchestrator->cancel($shipment, '   ', 'admin@example.com');
    }

    public function testAProviderBackedShipmentCannotBeHandedOverByHand(): void
    {
        // Marking a carrier shipment as handed over by hand would fake an acceptance the carrier
        // never gave, leaving a parcel the store believes is moving and the carrier has never heard of.
        $order = $this->confirmedOrder('fake-handover@example.com', 'carrier_express');
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('fulfilled by its carrier');

        $this->orchestrator->handOver($shipment, 'FAKE-TRK', 'admin@example.com');
    }

    public function testACarrierBackedShipmentIsNeverMarkedDeliveredOrMovingByHand(): void
    {
        // A store word that overrides the carrier's is how a customer ends up told their parcel
        // arrived while the courier still has it. `refreshStatus` is the only way a carrier-backed
        // parcel changes state, and it is offered on the same screen.
        $order = $this->confirmedOrder('no-manual-state@example.com', 'carrier_express');
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $shipment->markReady('FAKE-SHIP-MS', 'FAKE-TRK-MS', new \DateTimeImmutable());
        $this->entityManager->flush();
        $fresh = $this->reload($shipment->id());

        try {
            $this->orchestrator->markInTransit($fresh, 'admin@example.com');
            self::fail('A carrier parcel must not be marked in transit by hand.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('carrier', $exception->getMessage());
        }
        self::assertSame(ShipmentState::Ready, $this->reload($shipment->id())->state());

        try {
            $this->orchestrator->markDelivered($this->reload($shipment->id()), 'admin@example.com');
            self::fail('A carrier parcel must not be marked delivered by hand.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('carrier', $exception->getMessage());
        }
        self::assertSame(ShipmentState::Ready, $this->reload($shipment->id())->state());
    }

    public function testAParcelTheCarrierNeverTookCanStillBeCancelled(): void
    {
        // Nothing is at the carrier yet, so there is nothing to recall — the refusal in the
        // carrier path must not leave this parcel impossible to cancel.
        $order = $this->confirmedOrder('cancel-pending-carrier@example.com', 'carrier_express');
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        self::assertTrue($shipment->canBeCancelled());

        $cancelled = $this->orchestrator->cancel($shipment, 'Müşteri siparişten vazgeçti', 'admin@example.com');

        self::assertSame(ShipmentState::Cancelled, $cancelled->state());
        self::assertSame(0, $this->provider->createRequestCount());
    }

    public function testAnAlreadyQueuedCarrierCallDoesNotResurrectACancelledParcel(): void
    {
        $order = $this->confirmedOrder('cancel-then-message@example.com', 'carrier_express');
        $this->provider->queueCreation(ShipmentCreationOutcome::accepted('FAKE-SHIP-LATE', 'FAKE-TRK-LATE'));
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $this->orchestrator->cancel($this->reload($shipment->id()), 'Müşteri vazgeçti', 'admin@example.com');

        $this->handler(new CreateShipment($shipment->id()));

        self::assertSame(0, $this->provider->createRequestCount(), 'A cancelled parcel must never reach the carrier.');
        self::assertSame(ShipmentState::Cancelled, $this->reload($shipment->id())->state());
    }

    public function testACancellationIsNotRecordedAsAFailure(): void
    {
        // A recall is a decision, not a problem. Recording it in the failure columns made the
        // admin screen render the reason under a heading called "Last failure".
        $order = $this->confirmedOrder('cancel-not-failure@example.com');
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');

        $cancelled = $this->orchestrator->cancel($shipment, 'Müşteri vazgeçti', 'admin@example.com');

        self::assertSame(ShipmentState::Cancelled, $cancelled->state());
        self::assertNull($cancelled->failure());
        self::assertNotNull($cancelled->cancelledAt());
    }

    public function testCreatingAShipmentIsIdempotentEvenAfterTheMethodIsRetired(): void
    {
        // PHASE 17 will rename and remove carrier methods. A second press on an order whose method
        // no longer resolves must still return the shipment that exists: the parcel is already
        // recorded and nothing about it changes. Proven with a second orchestrator whose method
        // registry is empty, which is the situation a retired method creates.
        $order = $this->confirmedOrder('retired-method@example.com');
        $first = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');

        $withoutAnyMethod = new \App\Module\Shipping\ShipmentOrchestrator(
            new \App\Module\Shipping\ShippingMethodRegistry([]),
            self::getContainer()->get(\App\Module\Shipping\ShippingProviderRegistry::class),
            self::getContainer()->get(\App\Repository\Commerce\ShipmentRepository::class),
            self::getContainer()->get(\App\Module\Order\OrderRepositoryInterface::class),
            $this->entityManager,
            self::getContainer()->get(\Psr\Clock\ClockInterface::class),
            self::getContainer()->get(\Symfony\Component\Messenger\MessageBusInterface::class),
            self::getContainer()->get(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class),
        );

        $second = $withoutAnyMethod->createForOrder($order->orderNumber(), 'admin@example.com');

        self::assertSame($first->id(), $second->id());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_shipment'));
        // And the method is genuinely unresolvable there, or the test proves nothing.
        $this->expectException(\App\Module\Shipping\ShippingMethodNotAvailable::class);
        (new \App\Module\Shipping\ShippingMethodRegistry([]))->select('local_standard');
    }

    public function testAStatusReportedFailureDoesNotLetAStaleMessageReAskTheCarrier(): void
    {
        // A carrier that reports a failure is not a carrier that refused to take the parcel. If
        // that code were treated as retryable, a redelivered create message would re-ask for a
        // parcel the carrier has already accepted — the exact duplicate delivery this phase exists
        // to prevent. The invariant is invisible unless it is asserted.
        $shipment = $this->readyShipment('status-failure@example.com');
        $this->provider->queueStatus(ShipmentStatusReport::reporting(ShipmentState::Failed, null, 'Adres bulunamadı'));

        $failed = $this->orchestrator->refreshStatus($shipment, 'admin@example.com');

        self::assertSame(ShipmentState::Failed, $failed->state());
        self::assertSame('provider_reported_failure', $failed->failure()->code());
        self::assertFalse($failed->failure()->isRetryable());
        self::assertFalse($failed->awaitsProviderCreation(), 'A parcel the carrier already holds must never be created again.');
    }

    // ---------------------------------------------------------------- cross-cutting guarantees

    public function testNoShipmentActionEverMovesTheOrder(): void
    {
        $order = $this->confirmedOrder('order-untouched@example.com');
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $shipment = $this->orchestrator->handOver($shipment, 'VAN-4', 'admin@example.com');
        $this->orchestrator->markInTransit($shipment, 'admin@example.com');
        $this->orchestrator->markDelivered($this->reload($shipment->id()), 'admin@example.com');

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(CustomerOrder::class, $order->id());
        self::assertInstanceOf(CustomerOrder::class, $reloaded);
        self::assertSame(OrderState::Confirmed, $reloaded->state(), 'Delivering a parcel must not complete the order.');
    }

    public function testAnOrderTheStoreCannotPerformIsRefusedRatherThanFulfilledSomehowElse(): void
    {
        $order = $this->confirmedOrder('unknown-method@example.com', 'carrier_nowhere');

        $this->expectException(\App\Module\Shipping\ShippingMethodNotAvailable::class);

        $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
    }

    private function handler(CreateShipment $message): void
    {
        ($this->handler)($message);
    }

    private function queuedMessages(): int
    {
        // Counts CARRIER messages only. The doctrine transport keeps `headers` empty and puts the
        // message class in the serialized `body`, so the class name is the only discriminator —
        // its `queue_name` is `default` for every transport alike. Now that the store also mails its
        // customers, an unfiltered count would mix a `SendEmailMessage` into an assertion about
        // carrier work and fail for the wrong reason.
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE body LIKE ?',
            ['%CreateShipment%'],
        );
    }

    private function reload(int $shipmentId): Shipment
    {
        $this->entityManager->clear();
        $shipment = $this->shipments->find($shipmentId);

        self::assertInstanceOf(Shipment::class, $shipment);

        return $shipment;
    }

    private function readyShipment(string $email): Shipment
    {
        $shipment = Shipment::start($this->confirmedOrder($email, 'carrier_express'), 'carrier_express', 'Ekspres', 'fake', new \DateTimeImmutable());
        $this->shipments->save($shipment);
        $this->entityManager->flush();
        $shipment->markReady('FAKE-SHIP-'.substr(md5($email), 0, 6), 'FAKE-TRK-1', new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->reload($shipment->id());
    }

    private function confirmedOrder(string $email, string $methodKey = 'local_standard'): CustomerOrder
    {
        $customer = new CustomerUser($email, 'Efe', 'Yılmaz');
        $this->entityManager->persist($customer);
        $gross = Money::ofMinor(30_000, 'TRY');
        $zero = Money::ofMinor(0, 'TRY');
        $order = new CustomerOrder(
            'EOA-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(6))),
            $customer,
            $gross,
            $zero,
            $zero,
            $gross,
            $methodKey,
            'Ekspres',
            'local_manual',
            'Yerel manuel doğrulama',
            new \DateTimeImmutable(),
        );
        $order->addItem(null, 'BRK-1', 'Fren balatası', 2, Money::ofMinor(15_000, 'TRY'), 0, $gross, $zero, $gross);
        $order->addAddress(OrderAddressRole::Shipping, 'Efe Yılmaz', '05000000000', 'Atatürk Cad. 1', null, 'Seyhan', 'Adana', '01000', 'TR');
        $order->addAddress(OrderAddressRole::Billing, 'Efe Yılmaz', '05000000000', 'Atatürk Cad. 1', null, 'Seyhan', 'Adana', '01000', 'TR');
        $order->sealSnapshots();
        $this->entityManager->persist($order);
        $this->entityManager->flush();
        $order->transitionTo(OrderState::Confirmed);
        $this->entityManager->flush();

        return $order;
    }
}
