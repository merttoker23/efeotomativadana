<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use App\Shared\Money\DecimalAmount;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

/**
 * Structured data has to state a price as a decimal string, and money here is integer minor
 * units. Turning one into the other by dividing is how a store ends up publishing 124.999999
 * for a part that costs 125.00, so the conversion is integer arithmetic against the currency's
 * own exponent.
 */
final class DecimalAmountTest extends TestCase
{
    public function testMinorUnitsBecomeADecimalStringAtTheCurrencysOwnPrecision(): void
    {
        self::assertSame('125.00', DecimalAmount::forCurrency(Money::ofMinor(12_500, 'TRY')));
        self::assertSame('0.05', DecimalAmount::forCurrency(Money::ofMinor(5, 'TRY')));
        self::assertSame('0.00', DecimalAmount::forCurrency(Money::ofMinor(0, 'TRY')));
        self::assertSame('1000000.00', DecimalAmount::forCurrency(Money::ofMinor(100_000_000, 'TRY')));
    }

    public function testACurrencyWithoutDecimalPlacesIsWrittenWithoutADecimalPoint(): void
    {
        // JPY's ISO exponent is zero, so appending ".00" would be a different amount.
        self::assertSame('1250', DecimalAmount::forCurrency(Money::ofMinor(1_250, 'JPY')));
    }

    public function testAThreeDecimalCurrencyKeepsItsThirdPlace(): void
    {
        self::assertSame('1.234', DecimalAmount::forCurrency(Money::ofMinor(1_234, 'KWD')));
    }

    public function testTheOutputIsUngroupedAndUsesAFullStopWhateverTheStoreLocale(): void
    {
        // A Turkish rendering would be "1.234,56", which a machine reading a JSON-LD document
        // takes as the wrong number rather than as a localised spelling of the right one. The
        // method therefore takes no locale at all, and 1234.56 must already be ungrouped.
        self::assertSame('1234.56', DecimalAmount::forCurrency(Money::ofMinor(123_456, 'EUR')));
        self::assertSame('1234567.89', DecimalAmount::forCurrency(Money::ofMinor(123_456_789, 'EUR')));
    }

    public function testTheExponentIsTheCurrencyIsOwn(): void
    {
        self::assertSame(2, DecimalAmount::exponentOf('TRY'));
        self::assertSame(0, DecimalAmount::exponentOf('JPY'));
        self::assertSame(3, DecimalAmount::exponentOf('KWD'));
    }
}
