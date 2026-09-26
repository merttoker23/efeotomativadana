<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway\PayTR;

use App\Shared\Money\Money;

/**
 * The `user_basket` PayTR's Direct API expects: plain JSON, not the base64 form the token
 * endpoint used.
 *
 * When the order carries its own lines they are sent, so the customer's statement shows what was
 * bought. When it does not, a single line at the exact order total is sent instead of nothing:
 * PayTR compares its own basket total against `payment_amount`, and an empty or drifting basket
 * would make a correctly priced payment look wrong.
 */
final class PaytrBasket
{
    /**
     * @param list<array{string, string, int}> $lines
     */
    public static function encode(array $lines, string $fallbackLabel, string $fallbackAmount): string
    {
        if ([] === $lines) {
            $lines = [[$fallbackLabel, $fallbackAmount, 1]];
        }

        return json_encode(
            array_map(
                static fn (array $line): array => [$line[0], $line[1], $line[2]],
                $lines,
            ),
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );
    }
}
