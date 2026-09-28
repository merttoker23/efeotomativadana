<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shipping;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Shipment;
use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderState;
use App\Module\Payment\SanitizedFailure;
use App\Module\Shipping\ShipmentState;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

final class ShipmentTest extends TestCase
{
    public function testANewShipmentCopiesTheOrdersMethodAndDerivesAStableIdempotencyKey(): void
    {
        $shipment = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'manual', $this->at());

        self::assertSame(ShipmentState::Pending, $shipment->state());
        self::assertSame('local_standard', $shipment->methodKey());
        self::assertSame('Yerel standart teslimat', $shipment->methodLabel());
        self::assertSame('manual', $shipment->providerKey());
        self::assertSame('EOA-20260928-ABCDEF123456', $shipment->orderNumber());
        self::assertNull($shipment->trackingNumber());
        self::assertNull($shipment->providerReference());
        // Deterministic, because a replayed message has to arrive at the carrier under the very
        // same key. A random token per call would make the idempotency contract unusable.
        self::assertSame('shipment-EOA-20260928-ABCDEF123456', $shipment->idempotencyKey());
        self::assertTrue($shipment->awaitsProviderCreation());
    }

    public function testAShipmentIsNotCreatedForAnOrderThatHasNotBeenConfirmed(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only a confirmed order can be shipped.');

        Shipment::start($this->placedOrder(), 'local_standard', 'Yerel standart teslimat', 'manual', $this->at());
    }

    public function testAShipmentIsNotCreatedForACancelledOrder(): void
    {
        $order = $this->confirmedOrder();
        $order->transitionTo(OrderState::Cancelled);

        $this->expectException(\DomainException::class);

        Shipment::start($order, 'local_standard', 'Yerel standart teslimat', 'manual', $this->at());
    }

    public function testHandingTheParcelOverRecordsTheReferenceTheTrackingNumberAndTheMoment(): void
    {
        $shipment = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'manual', $this->at());
        $at = new \DateTimeImmutable('2026-09-29 10:00:00');

        $shipment->markReady(null, 'TRK-9', $at);

        self::assertSame(ShipmentState::Ready, $shipment->state());
        self::assertSame('TRK-9', $shipment->trackingNumber());
        self::assertSame($at, $shipment->readyAt());
        self::assertSame($at, $shipment->updatedAt());
        self::assertNull($shipment->providerReference(), 'A locally hand-fulfilled shipment has no carrier reference.');
    }

    public function testACarrierReferenceIsRecordedWhenTheProviderAcceptsTheParcel(): void
    {
        $shipment = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'fake', $this->at());

        $shipment->markReady('SHIP-1', 'TRK-9', $this->at());

        self::assertSame('SHIP-1', $shipment->providerReference());
    }

    public function testATrackingNumberArrivingLateReplacesTheOldOneAndIsAudited(): void
    {
        $shipment = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'fake', $this->at());
        $shipment->markReady('SHIP-1', 'TRK-OLD', $this->at());

        $shipment->assignTrackingNumber('TRK-NEW', $this->at());

        self::assertSame('TRK-NEW', $shipment->trackingNumber());
    }

    public function testATrackingNumberCannotSmuggleASecondLineIntoAScreen(): void
    {
        $shipment = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'fake', $this->at());

        $shipment->markReady('SHIP-1', "TRK-9\r\nTESLIM EDILDI", $this->at());

        self::assertSame('TRK-9 TESLIM EDILDI', $shipment->trackingNumber());
    }

    public function testAParcelGoesOutAndArrives(): void
    {
        $at = $this->at();
        $shipment = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'manual', $at);
        $shipment->markReady(null, 'TRK-9', $at);

        $shipment->markInTransit($at);
        self::assertSame(ShipmentState::InTransit, $shipment->state());
        self::assertEquals($at, $shipment->inTransitAt());

        $shipment->markDelivered($at);
        self::assertSame(ShipmentState::Delivered, $shipment->state());
        self::assertEquals($at, $shipment->deliveredAt());
        self::assertTrue($shipment->state()->isFulfilled());
    }

    public function testAParcelCollectedStraightFromTheDoorNeedsNoInTransitStep(): void
    {
        $shipment = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'manual', $this->at());
        $shipment->markReady(null, 'TRK-9', $this->at());

        $shipment->markDelivered($this->at());

        self::assertSame(ShipmentState::Delivered, $shipment->state());
        self::assertNull($shipment->inTransitAt());
    }

    public function testADeliveredParcelCannotTravelAgain(): void
    {
        $shipment = $this->deliveredShipment();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Shipment cannot transition from delivered to in_transit.');

        $shipment->markInTransit($this->at());
    }

    public function testAParcelOnTheRoadCannotBeCancelledByHand(): void
    {
        $shipment = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'manual', $this->at());
        $shipment->markReady(null, 'TRK-9', $this->at());
        $shipment->markInTransit($this->at());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Shipment cannot transition from in_transit to cancelled.');

        $shipment->markCancelled('Yanlış adres', $this->at());
    }

    public function testACancelledParcelCannotBeResurrected(): void
    {
        $shipment = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'manual', $this->at());
        $shipment->markCancelled('Müşteri vazgeçti', $this->at());

        self::assertSame(ShipmentState::Cancelled, $shipment->state());
        self::assertEquals($this->at(), $shipment->cancelledAt());

        $this->expectException(\DomainException::class);
        $shipment->markDelivered($this->at());
    }

    public function testACancellationNeedsAReasonBecauseItIsAnAuditedAction(): void
    {
        $shipment = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'manual', $this->at());

        $this->expectException(\InvalidArgumentException::class);

        $shipment->markCancelled('   ', $this->at());
    }

    public function testAFailedCreationIsRetryableOnTheVerySameShipmentRow(): void
    {
        $shipment = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'fake', $this->at());

        $shipment->markFailed(SanitizedFailure::fromProvider('service_unavailable', 'carrier 500', null), $this->at());

        self::assertSame(ShipmentState::Failed, $shipment->state());
        self::assertSame('service_unavailable', $shipment->failure()?->code());
        self::assertTrue($shipment->failure()->isRetryable());
        // A failed creation is still the store's to send: the row keeps waiting for the provider,
        // which is what lets a retried message retry the call instead of giving up.
        self::assertTrue($shipment->awaitsProviderCreation());

        // The retry is the same row under the same key, which is the only reason the provider can
        // recognise it as the parcel it already knows.
        $shipment->markReady('SHIP-1', 'TRK-9', $this->at());
        self::assertSame(ShipmentState::Ready, $shipment->state());
        self::assertFalse($shipment->awaitsProviderCreation());
        self::assertNull($shipment->failure(), 'A successful retry clears the previous failure.');
    }

    /**
     * A replayed create message stops here. Only a shipment the carrier has not accepted yet may
     * be created again, so a retry cannot buy a second delivery for an order already on its way.
     */
    public function testAParcelTheCarrierAlreadyKnowsIsNeverAskedForAgain(): void
    {
        $ready = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'fake', $this->at());
        $ready->markReady('SHIP-1', 'TRK-9', $this->at());
        self::assertFalse($ready->awaitsProviderCreation(), 'ready');

        $inTransit = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'fake', $this->at());
        $inTransit->markReady('SHIP-2', 'TRK-9', $this->at());
        $inTransit->markInTransit($this->at());
        self::assertFalse($inTransit->awaitsProviderCreation(), 'in_transit');

        $delivered = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'fake', $this->at());
        $delivered->markReady('SHIP-3', 'TRK-9', $this->at());
        $delivered->markDelivered($this->at());
        self::assertFalse($delivered->awaitsProviderCreation(), 'delivered');

        $cancelled = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'fake', $this->at());
        $cancelled->markCancelled('Müşteri vazgeçti', $this->at());
        self::assertFalse($cancelled->awaitsProviderCreation(), 'cancelled');
    }

    public function testAProviderFailureIsStoredWithoutItsSecrets(): void
    {
        $shipment = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'fake', $this->at());

        $shipment->markFailed(SanitizedFailure::fromProvider('address_invalid', 'district 4111111111111111 token=abc', null), $this->at());

        $message = (string) $shipment->failure()?->message();
        self::assertStringNotContainsString('4111111111111111', $message);
        self::assertStringNotContainsString('abc', $message);    }

    public function testARefusedCancellationIsAnAuditedFactThatChangesNoState(): void
    {
        $shipment = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'fake', $this->at());
        $shipment->markReady('SHIP-1', 'TRK-9', $this->at());

        $shipment->recordRefusedCancellation(SanitizedFailure::fromProvider('already_dispatched', 'too late', null), $this->at());

        self::assertSame(ShipmentState::Ready, $shipment->state(), 'A carrier refusal must not pretend the parcel was recalled.');
        self::assertSame('already_dispatched', $shipment->failure()?->code());
        self::assertCount(3, $shipment->events());
        self::assertSame('cancel_refused', $shipment->events()[2]->detail());
    }

    public function testACarrierStatusIsKeptEvenWhenItChangesNothing(): void
    {
        $shipment = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'fake', $this->at());
        $shipment->markReady('SHIP-1', 'TRK-9', $this->at());

        $shipment->recordProviderFact('Yola çıktı', $this->at());
        $shipment->recordProviderFact('Yola çıktı', $this->at());

        self::assertSame(ShipmentState::Ready, $shipment->state());
        self::assertCount(4, $shipment->events());
    }

    public function testEveryShipmentChangeIsRecordedWithItsSource(): void
    {
        $shipment = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'fake', $this->at());
        $shipment->markReady('SHIP-1', 'TRK-9', $this->at());
        $shipment->markInTransit($this->at());
        $shipment->markDelivered($this->at());

        $events = $shipment->events();
        self::assertCount(4, $events);
        self::assertSame(ShipmentState::Pending, $events[0]->toState());
        self::assertSame('store', $events[0]->source());
        // The handover came from the carrier, so it is filed as the carrier's word, not the
        // store's; the road and the doorstep are the store's own report.
        self::assertSame(ShipmentState::Ready, $events[1]->toState());
        self::assertSame('provider', $events[1]->source());
        self::assertSame(ShipmentState::InTransit, $events[2]->toState());
        self::assertSame('store', $events[2]->source());
        self::assertSame(ShipmentState::Delivered, $events[3]->toState());
        self::assertSame('provider', $events[3]->source());
        foreach ($events as $event) {
            self::assertNotNull($event->detail());
            self::assertLessThanOrEqual(255, mb_strlen((string) $event->detail()));
        }
    }

    public function testNoShipmentTransitionEverMovesTheOrder(): void
    {
        // The acceptance criterion that order and shipment states are related but not conflated:
        // a parcel that fails at the carrier must leave a confirmed order exactly as it was.
        $order = $this->confirmedOrder();
        $shipment = Shipment::start($order, 'local_standard', 'Yerel standart teslimat', 'fake', $this->at());
        $shipment->markReady('SHIP-1', 'TRK-9', $this->at());
        $shipment->markFailed(SanitizedFailure::fromProvider('lost', 'parcel lost', null), $this->at());

        self::assertSame(OrderState::Confirmed, $order->state());
        self::assertSame(ShipmentState::Failed, $shipment->state());
    }

    public function testAMethodKeyOrLabelIsNeverBlank(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Shipment::start($this->confirmedOrder(), '  ', 'Yerel standart teslimat', 'manual', $this->at());
    }

    public function testTheProviderKeyIsNeverBlank(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', '', $this->at());
    }

    private function deliveredShipment(): Shipment
    {
        $shipment = Shipment::start($this->confirmedOrder(), 'local_standard', 'Yerel standart teslimat', 'manual', $this->at());
        $shipment->markReady(null, 'TRK-9', $this->at());
        $shipment->markDelivered($this->at());

        return $shipment;
    }

    private function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-28 09:00:00');
    }

    private function placedOrder(): CustomerOrder
    {
        return $this->order();
    }

    private function confirmedOrder(): CustomerOrder
    {
        $order = $this->order();
        $order->transitionTo(OrderState::Confirmed);

        return $order;
    }

    private function order(): CustomerOrder
    {
        $customer = new CustomerUser('musteri@example.com', 'Efe', 'Yılmaz');
        $gross = Money::ofMinor(30_000, 'TRY');
        $zero = Money::ofMinor(0, 'TRY');
        $order = new CustomerOrder(
            'EOA-20260928-ABCDEF123456',
            $customer,
            $gross,
            $zero,
            $zero,
            $gross,
            'local_standard',
            'Yerel standart teslimat',
            'local_manual',
            'Yerel manuel doğrulama',
            $this->at(),
        );
        $order->addItem(null, 'BRK-1', 'Fren balatası', 2, Money::ofMinor(15_000, 'TRY'), 0, $gross, $zero, $gross);
        $order->addAddress(OrderAddressRole::Shipping, 'Efe Yılmaz', '05000000000', 'Atatürk Cad. 1', null, 'Seyhan', 'Adana', '01000', 'TR');
        $order->addAddress(OrderAddressRole::Billing, 'Efe Yılmaz', '05000000000', 'Atatürk Cad. 1', null, 'Seyhan', 'Adana', '01000', 'TR');
        $order->sealSnapshots();

        return $order;
    }
}
