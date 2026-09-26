<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Gateway\PayTR;

use App\Module\Payment\Gateway\PayTR\PaytrAmount;
use App\Shared\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PayTR is inconsistent about money on purpose, and both forms are needed.
 *
 * `payment_amount` and `total_amount` are integer minor units, while the refund's
 * `return_amount` is a decimal string with a dot. Sending the wrong one is silently
 * accepted by the provider and moves the wrong amount of money, so each direction is pinned.
 */
final class PaytrAmountTest extends TestCase
{
    public function testAnAmountIsSentToTheTokenRequestAsIntegerMinorUnits(): void
    {
        self::assertSame('159990', PaytrAmount::minorUnits(Money::ofMinor(159_990, 'TRY')));
    }

    public function testAnAmountIsSentToTheRefundRequestAsADecimalString(): void
    {
        self::assertSame('1599.90', PaytrAmount::decimal(Money::ofMinor(159_990, 'TRY')));
    }

    public function testASingleMinorUnitKeepsBothDecimalPlaces(): void
    {
        self::assertSame('0.01', PaytrAmount::decimal(Money::ofMinor(1, 'TRY')));
    }

    public function testAWholeAmountKeepsItsTrailingZeroes(): void
    {
        self::assertSame('45.00', PaytrAmount::decimal(Money::ofMinor(4_500, 'TRY')));
    }

    #[DataProvider('supportedCurrencies')]
    public function testEveryCurrencyPayTRAcceptsCanBeSent(string $currency): void
    {
        self::assertSame('10.00', PaytrAmount::decimal(Money::ofMinor(1_000, $currency)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function supportedCurrencies(): iterable
    {
        yield 'TRY' => ['TRY'];
        yield 'EUR' => ['EUR'];
        yield 'USD' => ['USD'];
        yield 'GBP' => ['GBP'];
        yield 'RUB' => ['RUB'];
    }

    #[DataProvider('unsupportedCurrencies')]
    public function testACurrencyPayTRDoesNotAcceptIsRefusedRatherThanGuessed(string $currency): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PaytrAmount::decimal(Money::ofMinor(1_000, $currency));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedCurrencies(): iterable
    {
        yield 'JPY has no minor units' => ['JPY'];
        yield 'KWD has three' => ['KWD'];
    }

    public function testTheProviderCurrencySpellingIsMappedOntoTheIsoCode(): void
    {
        self::assertSame('TRY', PaytrAmount::isoCurrency('TL'));
        self::assertSame('TRY', PaytrAmount::isoCurrency('try'));
        self::assertSame('USD', PaytrAmount::isoCurrency(' usd '));
        self::assertSame('EUR', PaytrAmount::isoCurrency(' EUR '));
    }

    public function testAnUnknownProviderCurrencyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PaytrAmount::isoCurrency('XYZ');
    }

    public function testTheWireSpellingIsWhatPaytrExpectsRatherThanTheIsoCode(): void
    {
        // PayTR documents the Turkish lira as TL, so TRY would be the wrong thing to send.
        self::assertSame('TL', PaytrAmount::wireCurrency('TRY'));
        self::assertSame('EUR', PaytrAmount::wireCurrency('EUR'));
        self::assertSame('USD', PaytrAmount::wireCurrency('usd'));
    }

    public function testACurrencyPaytrDoesNotAcceptHasNoWireSpelling(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PaytrAmount::wireCurrency('PLN');
    }

    public function testTheTwoDirectionsRoundTrip(): void
    {
        foreach (['TRY', 'EUR', 'USD', 'GBP', 'RUB'] as $iso) {
            self::assertSame($iso, PaytrAmount::isoCurrency(PaytrAmount::wireCurrency($iso)));
        }
    }
}
