<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway\PayTR;

/**
 * The store's order number as PayTR is given it.
 *
 * PayTR documents `merchant_oid` as alphanumeric and at most 64 characters, while the store's
 * order numbers are hyphenated. The same value is used for the token request, the notification
 * and the refund, so it is normalised here once instead of differently in each of the three
 * places. For the store's fixed `EOA-<date>-<hex>` format the transformation is injective, so
 * two different orders can never collapse onto one provider reference.
 */
final class PaytrOrderReference
{
    private const int MAX_LENGTH = 64;

    public static function forOrder(string $orderNumber): string
    {
        return self::reference($orderNumber, '');
    }

    /**
     * The reference for one specific attempt at an order.
     *
     * An order can be attempted more than once, and every attempt gets its own hosted payment
     * session at the provider. A reference carrying only the order number therefore cannot say
     * which session a notification belongs to, so a notification arriving after a retry could be
     * matched to the earlier attempt and quietly discarded as a replay — losing a real capture.
     * The attempt is part of the reference so the match is unambiguous, and because the refund
     * API is addressed by this same value, the refund still names the right transaction.
     */
    public static function forAttempt(string $orderNumber, string $attemptSequence): string
    {
        $sequence = preg_replace('/[^A-Za-z0-9]/', '', trim($attemptSequence)) ?? '';
        if ('' === $sequence) {
            throw new \InvalidArgumentException('A PayTR attempt reference needs an attempt identity.');
        }

        return self::reference($orderNumber, 'A'.$sequence);
    }

    private static function reference(string $orderNumber, string $suffix): string
    {
        $reference = preg_replace('/[^A-Za-z0-9]/', '', trim($orderNumber).$suffix) ?? '';
        if ('' === $reference) {
            throw new \InvalidArgumentException('A PayTR order reference must contain at least one alphanumeric character.');
        }
        if (mb_strlen($reference) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('A PayTR order reference must be at most 64 characters.');
        }

        return $reference;
    }
}
