<?php

namespace App\Tests\Unit\Pricing;

use App\Module\Pricing\LineTotalCalculator;
use App\Module\Pricing\LineTotals;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

final class LineTotalCalculatorTest extends TestCase
{
    public function testItCalculatesExactGrossNetAndTaxForMultipleUnits(): void
    {
        $totals = (new LineTotalCalculator())->calculate(
            Money::ofMinor(1250, 'TRY'),
            TaxRate::fromPercentage(20),
            3,
        );

        self::assertSame(1250, $totals->unitGross()->minorAmount());
        self::assertSame(3, $totals->quantity());
        self::assertSame(3750, $totals->gross()->minorAmount());
        self::assertSame(3125, $totals->net()->minorAmount());
        self::assertSame(625, $totals->tax()->minorAmount());
        self::assertSame('TRY', $totals->tax()->currency());
    }

    public function testItRejectsZeroQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new LineTotalCalculator())->calculate(
            Money::ofMinor(1250, 'TRY'),
            TaxRate::fromPercentage(20),
            0,
        );
    }

    public function testItRejectsFloatQuantityAtTheDomainBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new LineTotalCalculator())->calculate(
            Money::ofMinor(1250, 'TRY'),
            TaxRate::fromPercentage(20),
            1.5,
        );
    }

    public function testItRejectsLineTotalsWithANonPositiveQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LineTotals(
            Money::ofMinor(100, 'TRY'),
            0,
            Money::ofMinor(0, 'TRY'),
            Money::ofMinor(0, 'TRY'),
            Money::ofMinor(0, 'TRY'),
        );
    }

    public function testItRejectsLineTotalsWithMixedCurrencies(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LineTotals(
            Money::ofMinor(100, 'TRY'),
            1,
            Money::ofMinor(100, 'EUR'),
            Money::ofMinor(100, 'EUR'),
            Money::ofMinor(0, 'EUR'),
        );
    }

    public function testItRejectsLineTotalsWhoseGrossDoesNotMatchTheUnitPriceAndQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LineTotals(
            Money::ofMinor(100, 'TRY'),
            2,
            Money::ofMinor(201, 'TRY'),
            Money::ofMinor(201, 'TRY'),
            Money::ofMinor(0, 'TRY'),
        );
    }

    public function testItRejectsLineTotalsWhoseGrossDoesNotEqualNetPlusTax(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LineTotals(
            Money::ofMinor(100, 'TRY'),
            2,
            Money::ofMinor(200, 'TRY'),
            Money::ofMinor(100, 'TRY'),
            Money::ofMinor(99, 'TRY'),
        );
    }
}
