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
use App\Module\Shipping\ShipmentTrackingView;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

final class ShipmentTrackingViewTest extends TestCase
{
    public function testAHandDeliveredParcelReadsInPlainTurkishWithItsOwnTrackingNumber(): void
    {
        $view = ShipmentTrackingView::forShipment($this->inTransit('VAN-ADI-2026-09-28-1', 'Yerel standart teslimat'));

        self::assertSame('Yolda', $view->stateLabel());
        self::assertSame('Yerel standart teslimat', $view->methodLabel());
        self::assertSame('VAN-ADI-2026-09-28-1', $view->trackingNumber());
        self::assertTrue($view->hasTrackingNumber());
        self::assertSame('manual', $view->providerKey());
    }

    public function testACarrierParcelSaysWhichCarrierItIsWith(): void
    {
        $view = ShipmentTrackingView::forShipment($this->ready('FAKE-TRK-1', 'Ekspres kargo', 'fake'));

        self::assertSame('fake', $view->providerKey());
        self::assertSame('FAKE-TRK-1', $view->trackingNumber());
        self::assertTrue($view->isCarrierBacked());
    }

    public function testAHandDeliveredParcelIsNotCarrierBacked(): void
    {
        self::assertFalse(ShipmentTrackingView::forShipment($this->ready('VAN-1', 'Yerel standart teslimat', 'manual'))->isCarrierBacked());
    }

    public function testAParcelWithNoTrackingNumberSaysSoRatherThanShowingNothing(): void
    {
        $view = ShipmentTrackingView::forShipment($this->pending());

        self::assertFalse($view->hasTrackingNumber());
        self::assertSame('', $view->trackingNumber());
    }

    /**
     * The state a customer is told is chosen from the store's own enum, never from provider text.
     * A carrier string claiming a parcel was delivered therefore cannot change the state label —
     * it can only ever land in the tracking-number field, which a template escapes.
     */
    public function testAProviderClaimingDeliveryCannotChangeTheStateLabel(): void
    {
        $shipment = $this->pending();
        $shipment->assignTrackingNumber('TESLIM EDILDI', new \DateTimeImmutable());

        $view = ShipmentTrackingView::forShipment($shipment);

        self::assertSame(ShipmentState::Pending, $shipment->state());
        self::assertSame('Hazırlanıyor', $view->stateLabel());
        self::assertFalse($view->isFulfilled());
    }

    public function testATrackingNumberIsAlwaysOneBoundedLine(): void
    {
        $shipment = $this->pending();
        $shipment->assignTrackingNumber("TRK-1\r\nTESLIM EDILDI\r\n", new \DateTimeImmutable());

        $trackingNumber = ShipmentTrackingView::forShipment($shipment)->trackingNumber();

        self::assertStringNotContainsString("\n", $trackingNumber);
        self::assertStringNotContainsString("\r", $trackingNumber);
        self::assertLessThanOrEqual(120, mb_strlen($trackingNumber));
    }

    public function testAMethodLabelIsBoundedBeforeItIsRendered(): void
    {
        $view = ShipmentTrackingView::forShipment($this->ready('T-1', str_repeat('a', 500), 'fake'));

        self::assertLessThanOrEqual(120, mb_strlen($view->methodLabel()));
    }

    public function testEveryStateHasAHumanLabelAndNoneIsItsOwnRawValue(): void
    {
        $labels = [];
        foreach (ShipmentState::cases() as $state) {
            $labels[$state->value] = ShipmentTrackingView::stateLabelFor($state);
            self::assertNotSame($state->value, $labels[$state->value], sprintf('%s must not be shown as its own raw value.', $state->value));
        }

        self::assertSame([
            'pending' => 'Hazırlanıyor',
            'ready' => 'Hazır',
            'in_transit' => 'Yolda',
            'delivered' => 'Teslim edildi',
            'cancelled' => 'İptal edildi',
            'failed' => 'Başarısız',
        ], $labels);
    }

    public function testOnlyADeliveredParcelIsDescribedAsFulfilled(): void
    {
        self::assertTrue(ShipmentTrackingView::forShipment($this->delivered())->isFulfilled());
        self::assertFalse(ShipmentTrackingView::forShipment($this->inTransit('T-1'))->isFulfilled());
        self::assertFalse(ShipmentTrackingView::forShipment($this->cancelled())->isFulfilled());
        self::assertFalse(ShipmentTrackingView::forShipment($this->failed())->isFulfilled());
    }

    public function testTheOrderNumberIsCarriedSoATemplateCanLinkToIt(): void
    {
        self::assertSame('EOA-20260928-ABCDEF123456', ShipmentTrackingView::forShipment($this->pending())->orderNumber());
    }

    private function pending(): Shipment
    {
        return Shipment::start($this->order(), 'local_standard', 'Yerel standart teslimat', 'manual', new \DateTimeImmutable('2026-09-28 09:00:00'));
    }

    private function ready(string $trackingNumber, string $methodLabel = 'Ekspres kargo', string $providerKey = 'fake'): Shipment
    {
        $shipment = Shipment::start($this->order(), 'local_standard', $methodLabel, $providerKey, new \DateTimeImmutable('2026-09-28 09:00:00'));
        $shipment->markReady('fake' === $providerKey ? 'FAKE-SHIP-1' : null, $trackingNumber, new \DateTimeImmutable('2026-09-28 10:00:00'));

        return $shipment;
    }

    private function inTransit(string $trackingNumber, string $methodLabel = 'Yerel standart teslimat'): Shipment
    {
        $shipment = $this->ready($trackingNumber, $methodLabel, 'manual');
        $shipment->markInTransit(new \DateTimeImmutable('2026-09-28 12:00:00'));

        return $shipment;
    }

    private function delivered(): Shipment
    {
        $shipment = $this->ready('FAKE-TRK-1', 'Ekspres kargo', 'fake');
        $shipment->markDelivered(new \DateTimeImmutable('2026-09-28 15:00:00'));

        return $shipment;
    }

    private function cancelled(): Shipment
    {
        $shipment = $this->pending();
        $shipment->markCancelled('Müşteri vazgeçti', new \DateTimeImmutable('2026-09-28 11:00:00'));

        return $shipment;
    }

    private function failed(): Shipment
    {
        $shipment = $this->ready('FAKE-TRK-1', 'Ekspres kargo', 'fake');
        $shipment->markFailed(SanitizedFailure::fromProvider('lost', 'parcel lost', null), new \DateTimeImmutable('2026-09-28 16:00:00'));

        return $shipment;
    }

    private function order(): CustomerOrder
    {
        $order = new CustomerOrder(
            'EOA-20260928-ABCDEF123456',
            new CustomerUser('musteri@example.com', 'Efe', 'Yılmaz'),
            Money::ofMinor(30_000, 'TRY'),
            Money::ofMinor(0, 'TRY'),
            Money::ofMinor(0, 'TRY'),
            Money::ofMinor(30_000, 'TRY'),
            'local_standard',
            'Yerel standart teslimat',
            'local_manual',
            'Yerel manuel doğrulama',
            new \DateTimeImmutable('2026-09-28 08:00:00'),
        );
        $gross = Money::ofMinor(30_000, 'TRY');
        $order->addItem(null, 'BRK-1', 'Fren balatası', 2, Money::ofMinor(15_000, 'TRY'), 0, $gross, Money::ofMinor(0, 'TRY'), $gross);
        $order->addAddress(OrderAddressRole::Shipping, 'Efe Yılmaz', '05000000000', 'Atatürk Cad. 1', null, 'Seyhan', 'Adana', '01000', 'TR');
        $order->addAddress(OrderAddressRole::Billing, 'Efe Yılmaz', '05000000000', 'Atatürk Cad. 1', null, 'Seyhan', 'Adana', '01000', 'TR');
        $order->sealSnapshots();
        $order->transitionTo(OrderState::Confirmed);

        return $order;
    }
}
