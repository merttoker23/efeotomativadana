<?php

declare(strict_types=1);

namespace App\Module\Shipping\Gateway;

use App\Shared\Money\Money;

/**
 * Everything a carrier needs to take one parcel, and nothing it does not.
 *
 * The idempotency key is derived from the order and is therefore identical on every retry. A
 * carrier that honours it returns the same reference for the same key, which is what makes a
 * replayed message free of consequences; an adapter that cannot honour it must refuse rather
 * than risk a duplicate delivery.
 */
final readonly class ShipmentCreationInstruction
{
    private string $providerKey;

    private string $idempotencyKey;

    private string $orderNumber;

    private string $methodKey;

    private string $methodLabel;

    /** @var list<ShipmentItem> */
    private array $items;

    /**
     * @param list<ShipmentItem> $items
     */
    public function __construct(
        string $providerKey,
        string $idempotencyKey,
        string $orderNumber,
        string $methodKey,
        string $methodLabel,
        private ShipmentAddress $address,
        array $items,
        private Money $declaredValue,
    ) {
        $this->providerKey = self::required($providerKey, 'Shipment provider key is required.');
        $this->idempotencyKey = self::required($idempotencyKey, 'Shipment idempotency key is required.');
        $this->orderNumber = self::required($orderNumber, 'Shipment order number is required.');
        $this->methodKey = self::required($methodKey, 'Shipment method key is required.');
        $this->methodLabel = self::required($methodLabel, 'Shipment method label is required.');

        if (1 !== preg_match('/^EOA-\d{8}-[0-9A-F]{12}$/', $this->orderNumber)) {
            throw new \InvalidArgumentException('Shipment order number has an invalid format.');
        }
        if ($declaredValue->isZero()) {
            throw new \InvalidArgumentException('Shipment declared value must be greater than zero.');
        }

        // A shipment with nothing in it is not a shipment, and a carrier asked to carry nothing
        // would either refuse or invent a parcel. Unlike the payment basket, there is no structure
        // to validate here: the input is already a list of typed item objects.
        if ([] === $items) {
            throw new \InvalidArgumentException('A shipment must carry at least one item.');
        }

        $this->items = $items;
    }

    public function providerKey(): string { return $this->providerKey; }

    /** The parcel's identity at the carrier. Identical on every retry of the same order. */
    public function idempotencyKey(): string { return $this->idempotencyKey; }

    public function orderNumber(): string { return $this->orderNumber; }
    public function methodKey(): string { return $this->methodKey; }
    public function methodLabel(): string { return $this->methodLabel; }
    public function address(): ShipmentAddress { return $this->address; }
    public function declaredValue(): Money { return $this->declaredValue; }

    /** @return list<ShipmentItem> */
    public function items(): array { return $this->items; }

    public function totalQuantity(): int
    {
        $quantity = 0;
        foreach ($this->items as $item) {
            $quantity += $item->quantity();
        }

        return $quantity;
    }

    public function contentsSummary(): string
    {
        return implode(', ', array_map(static fn (ShipmentItem $item): string => $item->label(), $this->items));
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
