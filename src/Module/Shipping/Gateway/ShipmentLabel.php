<?php

declare(strict_types=1);

namespace App\Module\Shipping\Gateway;

use App\Module\Shipping\ShipmentText;

/**
 * A carrier's printable label for one parcel.
 *
 * The document URL is validated as an absolute https URL at construction, and this phase never
 * renders it: an operator screen that turns an arbitrary carrier host into a clickable link is
 * a phishing primitive, and deciding which hosts are trusted belongs with the concrete carrier
 * that owns the host list. The tracking number is what an operator and a customer read.
 */
final readonly class ShipmentLabel
{
    private string $trackingNumber;

    private string $documentUrl;

    public function __construct(string $trackingNumber, string $documentUrl)
    {
        $trackingNumber = ShipmentText::tracking($trackingNumber);
        if (null === $trackingNumber) {
            throw new \InvalidArgumentException('A shipment label needs a tracking number.');
        }

        $documentUrl = trim($documentUrl);
        if (false === filter_var($documentUrl, FILTER_VALIDATE_URL) || 'https' !== parse_url($documentUrl, PHP_URL_SCHEME)) {
            throw new \InvalidArgumentException('A shipment label document must be an absolute https URL.');
        }

        $this->trackingNumber = $trackingNumber;
        $this->documentUrl = $documentUrl;
    }

    public function trackingNumber(): string { return $this->trackingNumber; }
    public function documentUrl(): string { return $this->documentUrl; }
}
