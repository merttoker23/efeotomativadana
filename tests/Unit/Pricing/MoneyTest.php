<?php

namespace App\Tests\Unit\Pricing;

use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testItNormalizesIsoCurrencyAndIdentifiesZeroAmounts(): void
    {
        $money = Money::ofMinor(0, 'try');

        self::assertSame(0, $money->minorAmount());
        self::assertSame('TRY', $money->currency());
        self::assertTrue($money->isZero());
    }

    public function testItRejectsUnknownIsoCurrencyCodes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::ofMinor(100, 'ZZZ');
    }

    public function testItRejectsFloatMinorAmountsAtTheDomainBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::ofMinor(100.5, 'TRY');
    }

    public function testItComparesAndAddsAmountsInTheSameCurrency(): void
    {
        $subtotal = Money::ofMinor(1250, 'TRY')->add(Money::ofMinor(375, 'TRY'));

        self::assertTrue($subtotal->equals(Money::ofMinor(1625, 'TRY')));
        self::assertFalse($subtotal->equals(Money::ofMinor(1625, 'EUR')));
    }

    public function testItRejectsCrossCurrencyArithmetic(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::ofMinor(100, 'TRY')->add(Money::ofMinor(100, 'EUR'));
    }

    public function testItRejectsCrossCurrencySubtraction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::ofMinor(100, 'TRY')->subtract(Money::ofMinor(100, 'EUR'));
    }

    public function testItMultipliesMinorUnitsExactly(): void
    {
        $total = Money::ofMinor(1250, 'TRY')->multiply(3);

        self::assertSame(3750, $total->minorAmount());
        self::assertSame('TRY', $total->currency());
    }

    public function testItRejectsNegativeMinorAmounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::ofMinor(-1, 'TRY');
    }

    public function testItRejectsNegativeQuantities(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::ofMinor(100, 'TRY')->multiply(-1);
    }

    public function testItRejectsFloatQuantitiesAtTheDomainBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::ofMinor(100, 'TRY')->multiply(1.5);
    }

    public function testItRejectsSubtractionThatWouldProduceANegativeAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::ofMinor(0, 'TRY')->subtract(Money::ofMinor(1, 'TRY'));
    }

    public function testItRejectsIntegerOverflowDuringArithmetic(): void
    {
        $maximum = Money::ofMinor(PHP_INT_MAX, 'TRY');

        $this->expectException(\OverflowException::class);

        $maximum->multiply(2);
    }

    public function testItRejectsIntegerOverflowDuringAddition(): void
    {
        $this->expectException(\OverflowException::class);

        Money::ofMinor(PHP_INT_MAX, 'TRY')->add(Money::ofMinor(1, 'TRY'));
    }
}
