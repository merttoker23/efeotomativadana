<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway\PayTR;

use App\Shared\Money\Money;

/**
 * Converts the store's exact {@see Money} into the two different shapes PayTR expects.
 *
 * The token request and the notification carry integer minor units, while the refund request
 * carries a decimal string. Sending the wrong one is accepted by the provider and moves the
 * wrong amount of money, so the two directions are deliberately separate methods.
 */
final class PaytrAmount
{
    /**
     * The currencies PayTR documents for this integration, in the spelling PayTR itself uses.
     *
     * All of them have two minor digits, which is what makes the decimal conversion exact rather
     * than approximate. The wire spelling is not always the ISO code — PayTR writes the Turkish
     * lira as `TL` — so the two directions are separate, explicit functions rather than one
     * helper that could quietly send a code the receiving end does not know.
     */
    private const array WIRE_TO_ISO = [
        'TL' => 'TRY',
        'TRY' => 'TRY',
        'EUR' => 'EUR',
        'USD' => 'USD',
        'GBP' => 'GBP',
        'RUB' => 'RUB',
    ];

    private const array ISO_TO_WIRE = [
        'TRY' => 'TL',
        'EUR' => 'EUR',
        'USD' => 'USD',
        'GBP' => 'GBP',
        'RUB' => 'RUB',
    ];

    /** Minor units, as `payment_amount` and `total_amount` are sent. */
    public static function minorUnits(Money $money): string
    {
        self::assertSupported($money);

        return (string) $money->minorAmount();
    }

    /** A dot-decimal string, as the refund request's `return_amount` is sent. */
    public static function decimal(Money $money): string
    {
        self::assertSupported($money);

        // Split rather than divide: a float round-trip would be inexact for large amounts, and
        // this is the figure a refund actually moves.
        $minor = $money->minorAmount();

        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }

    /** The ISO-4217 code for a currency as PayTR spells it, or a refusal rather than a guess. */
    public static function isoCurrency(string $wire): string
    {
        $iso = self::WIRE_TO_ISO[strtoupper(trim($wire))] ?? null;
        if (null === $iso) {
            throw new \InvalidArgumentException(sprintf('PayTR does not accept the currency "%s".', trim($wire)));
        }

        return $iso;
    }

    /** The spelling PayTR expects on the wire for an ISO-4217 code. */
    public static function wireCurrency(string $iso): string
    {
        $iso = strtoupper(trim($iso));
        $wire = self::ISO_TO_WIRE[$iso] ?? null;
        if (null === $wire) {
            throw new \InvalidArgumentException(sprintf('PayTR does not accept the currency "%s".', $iso));
        }

        return $wire;
    }

    private static function assertSupported(Money $money): void
    {
        if (!isset(self::ISO_TO_WIRE[$money->currency()])) {
            throw new \InvalidArgumentException(sprintf('PayTR does not accept the currency "%s".', $money->currency()));
        }
    }
}
