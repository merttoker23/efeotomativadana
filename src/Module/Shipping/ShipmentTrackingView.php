<?php

declare(strict_types=1);

namespace App\Module\Shipping;

use App\Entity\Commerce\Shipment;

/**
 * The one place a shipment is turned into strings a screen may show.
 *
 * Every string here is chosen from data the store owns — the state enum, the method snapshot taken
 * at checkout — except the tracking number, which came from a carrier. Two consequences matter:
 *
 * - a carrier cannot make a screen claim something the store never decided, because the state
 *   label is looked up from {@see ShipmentState} and provider wording never reaches it;
 * - a carrier cannot make a screen a different size or a second line, because the tracking number
 *   and the method label are reduced to one bounded printable line before they get here.
 *
 * Templates still escape their output. This class guarantees what the text *is*, not that it is
 * inert, and deliberately renders nothing that a provider URL could turn into a link.
 */
final readonly class ShipmentTrackingView
{
    private function __construct(
        private string $orderNumber,
        private string $providerKey,
        private string $methodLabel,
        private ShipmentState $state,
        private string $trackingNumber,
    ) {
    }

    public static function forShipment(Shipment $shipment): self
    {
        return new self(
            $shipment->orderNumber(),
            $shipment->providerKey(),
            // The label is the store's own checkout snapshot, bounded here as well because a
            // retired service's wording may have been edited in the past and grown.
            ShipmentText::status($shipment->methodLabel(), 120) ?? '',
            $shipment->state(),
            ShipmentText::tracking($shipment->trackingNumber()) ?? '',
        );
    }

    /** Turkish, for the operator and customer screens this store actually has. */
    public static function stateLabelFor(ShipmentState $state): string
    {
        return match ($state) {
            ShipmentState::Pending => 'Hazırlanıyor',
            ShipmentState::Ready => 'Hazır',
            ShipmentState::InTransit => 'Yolda',
            ShipmentState::Delivered => 'Teslim edildi',
            ShipmentState::Cancelled => 'İptal edildi',
            ShipmentState::Failed => 'Başarısız',
        };
    }

    public function orderNumber(): string { return $this->orderNumber; }

    public function providerKey(): string { return $this->providerKey; }

    public function methodLabel(): string { return $this->methodLabel; }

    public function state(): ShipmentState { return $this->state; }

    public function stateLabel(): string { return self::stateLabelFor($this->state); }

    /** Empty rather than null, so a template never has to decide what "none" looks like. */
    public function trackingNumber(): string { return $this->trackingNumber; }

    public function hasTrackingNumber(): bool { return '' !== $this->trackingNumber; }

    public function isCarrierBacked(): bool { return LocalManualShippingMethod::MANUAL_PROVIDER_KEY !== $this->providerKey; }

    public function isFulfilled(): bool { return $this->state->isFulfilled(); }
}
