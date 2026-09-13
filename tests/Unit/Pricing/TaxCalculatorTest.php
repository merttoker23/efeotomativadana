<?php

namespace App\Tests\Unit\Pricing;

use App\Module\Pricing\TaxCalculator;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

final class TaxCalculatorTest extends TestCase
{
    public function testItKeepsZeroTaxInclusiveAmountsAtZero(): void
    {
        $breakdown = (new TaxCalculator())->fromTaxInclusive(Money::ofMinor(0, 'TRY'), TaxRate::fromPercentage(20));

        self::assertSame(0, $breakdown->gross()->minorAmount());
        self::assertSame(0, $breakdown->net()->minorAmount());
        self::assertSame(0, $breakdown->tax()->minorAmount());
        self::assertSame('TRY', $breakdown->net()->currency());
    }

    public function testItSplitsA20PercentTaxInclusiveAmountIntoHandDerivedNetAndTax(): void
    {
        $breakdown = (new TaxCalculator())->fromTaxInclusive(Money::ofMinor(12000, 'TRY'), TaxRate::fromPercentage(20));

        self::assertSame(12000, $breakdown->gross()->minorAmount());
        self::assertSame(10000, $breakdown->net()->minorAmount());
        self::assertSame(2000, $breakdown->tax()->minorAmount());
    }

    public function testItRoundsTheInclusiveNetAmountHalfUpAtTheBoundary(): void
    {
        $breakdown = (new TaxCalculator())->fromTaxInclusive(Money::ofMinor(9, 'TRY'), TaxRate::fromPercentage(20));

        self::assertSame(8, $breakdown->net()->minorAmount());
        self::assertSame(1, $breakdown->tax()->minorAmount());
    }

    public function testItExpressesPercentageRatesAsBasisPoints(): void
    {
        self::assertSame(2000, TaxRate::fromPercentage(20)->basisPoints());
        self::assertSame(725, TaxRate::fromBasisPoints(725)->basisPoints());
    }

    public function testItAcceptsTaxRateEndpoints(): void
    {
        self::assertSame(0, TaxRate::fromPercentage(0)->basisPoints());
        self::assertSame(10000, TaxRate::fromPercentage(100)->basisPoints());
        self::assertSame(0, TaxRate::fromBasisPoints(0)->basisPoints());
        self::assertSame(10000, TaxRate::fromBasisPoints(10000)->basisPoints());
    }

    public function testItRejectsFloatPercentageRatesAtTheDomainBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TaxRate::fromPercentage(20.5);
    }

    public function testItRejectsFloatBasisPointRatesAtTheDomainBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TaxRate::fromBasisPoints(2000.5);
    }

    public function testItRejectsTaxRatesOutsideTheSupportedRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TaxRate::fromPercentage(101);
    }

    public function testItRejectsBasisPointsOutsideTheSupportedRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TaxRate::fromBasisPoints(10001);
    }

    public function testItNormalizesProviderNeutralTaxCategoryKeys(): void
    {
        self::assertSame('standard', TaxCategory::standard()->key());
        self::assertSame('reduced-rate', TaxCategory::of(' Reduced-Rate ')->key());
    }

    public function testItRejectsInvalidTaxCategoryKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TaxCategory::of('standard rate');
    }
}
