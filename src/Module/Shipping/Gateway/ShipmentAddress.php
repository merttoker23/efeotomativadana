<?php

declare(strict_types=1);

namespace App\Module\Shipping\Gateway;

/**
 * Where one parcel is going, as the order recorded it.
 *
 * This is a copy taken from the order's sealed shipping snapshot, not a live customer address:
 * an order that has been placed must be shippable to the address that was confirmed with the
 * customer, even if the address book has changed since.
 */
final readonly class ShipmentAddress
{
    private string $recipientName;

    private string $phone;

    private string $addressLine1;

    private ?string $addressLine2;

    private string $district;

    private string $city;

    private ?string $postalCode;

    private string $countryCode;

    public function __construct(
        string $recipientName,
        string $phone,
        string $addressLine1,
        ?string $addressLine2,
        string $district,
        string $city,
        ?string $postalCode,
        string $countryCode,
    ) {
        $this->recipientName = self::required($recipientName, 'Shipment recipient name is required.');
        $this->phone = self::required($phone, 'Shipment recipient phone is required.');
        $this->addressLine1 = self::required($addressLine1, 'Shipment street address is required.');
        $this->district = self::required($district, 'Shipment district is required.');
        $this->city = self::required($city, 'Shipment city is required.');
        $this->addressLine2 = self::optional($addressLine2);
        $this->postalCode = self::optional($postalCode);

        $countryCode = strtoupper(trim($countryCode));
        if (1 !== preg_match('/^[A-Z]{2}$/', $countryCode)) {
            throw new \InvalidArgumentException('Shipment country must be a two-letter country code.');
        }
        $this->countryCode = $countryCode;
    }

    public function recipientName(): string { return $this->recipientName; }
    public function phone(): string { return $this->phone; }
    public function addressLine1(): string { return $this->addressLine1; }
    public function addressLine2(): ?string { return $this->addressLine2; }
    public function district(): string { return $this->district; }
    public function city(): string { return $this->city; }
    public function postalCode(): ?string { return $this->postalCode; }
    public function countryCode(): string { return $this->countryCode; }

    /** One line, for a carrier field that only accepts a single string. */
    public function singleLine(): string
    {
        $parts = array_filter(
            [$this->addressLine1, $this->addressLine2, $this->district, $this->city, $this->postalCode, $this->countryCode],
            static fn (?string $part): bool => null !== $part && '' !== $part,
        );

        return implode(' ', $parts);
    }

    private static function required(string $value, string $message): string
    {
        $value = trim($value);
        if ('' === $value) {
            throw new \InvalidArgumentException($message);
        }

        return $value;
    }

    private static function optional(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
