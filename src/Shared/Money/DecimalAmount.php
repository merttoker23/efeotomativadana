<?php

declare(strict_types=1);

namespace App\Shared\Money;

use Symfony\Component\Intl\Currencies;

/**
 * Renders money as the plain decimal string the outside world expects.
 *
 * JSON-LD states a price as a decimal; money here is integer minor units plus a currency. The
 * obvious conversion — divide by 100 — is exactly where a published price goes wrong, because
 * 123_456 minor units in binary floating point is 1234.56 only approximately, and a
 * search-engine price that disagrees with the checkout by a cent is a support ticket nobody
 * can reproduce.
 *
 * So the amount is rendered with integer arithmetic only. The number of decimal places is a
 * property of the currency, not of the store: JPY has none, TRY has two, KWD has three, and a
 * format string that assumed "two" would publish a Japanese price ten times too large.
 *
 * There is deliberately no locale parameter. A Turkish locale would render 1234.56 as
 * "1.234,56", which a machine reading a JSON-LD document interprets as the wrong number rather
 * than as a localised spelling of the right one, so grouping and separators are fixed and the
 * output is ungrouped.
 */
final class DecimalAmount
{
    /**
     * Currency code => decimal places, measured once per process.
     *
     * A product page renders one of these per product, and the ICU format is not free. The
     * answer cannot change while the process runs, so it is remembered; a currency that fails
     * the probe is not cached, so a later correct answer is still possible.
     *
     * @var array<string, int>
     */
    private static array $exponents = [];

    /**
     * How many decimal places this currency is actually written with.
     *
     * Read from ICU by formatting exactly one major unit and looking at what comes after the
     * decimal separator. 1.0 is exact in binary floating point, so the float is only a probe
     * for the currency's precision — no amount is ever computed through it. The alternative,
     * a hard-coded table of ISO 4217 exponents, is one entry short somewhere and misprices a
     * currency silently.
     */
    public static function exponentOf(string $currency): int
    {
        $currency = strtoupper(trim($currency));
        if (isset(self::$exponents[$currency])) {
            return self::$exponents[$currency];
        }

        if (!Currencies::exists($currency)) {
            throw new \InvalidArgumentException('Currency must be a valid ISO-4217 code.');
        }

        // "en" is fixed on purpose: it is the one locale whose decimal separator is a full
        // stop, so the digit count below cannot be confused with a grouping separator.
        // The currency is known good by this point, so the formatter cannot fail on it.
        $formatter = new \NumberFormatter('en', \NumberFormatter::CURRENCY);
        $formatted = $formatter->formatCurrency(1.0, $currency);
        $separator = (string) $formatter->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);

        if ('' === $separator) {
            return 2;
        }

        $position = strrpos($formatted, $separator);

        return self::$exponents[$currency] = false === $position ? 0 : strlen(substr($formatted, $position + 1));
    }

    public static function forCurrency(Money $money): string
    {
        $exponent = self::exponentOf($money->currency());
        $minor = $money->minorAmount();

        if (0 === $exponent) {
            return (string) $minor;
        }

        $digits = str_pad((string) $minor, $exponent + 1, '0', \STR_PAD_LEFT);

        return substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);
    }
}
