<?php

namespace App\Tests\Unit\Pricing;

use App\Module\Pricing\PercentageDiscountCalculator;
use App\Shared\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PercentageDiscountCalculatorTest extends TestCase
{
    #[DataProvider('discounts')]
    public function testItAppliesPercentageDiscountsWithExactHalfUpRounding(int $amount, int $basisPoints, int $expected): void
    {
        $discounted = (new PercentageDiscountCalculator())->apply(Money::ofMinor($amount, 'TRY'), $basisPoints);

        self::assertSame($expected, $discounted->minorAmount());
        self::assertSame('TRY', $discounted->currency());
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function discounts(): iterable
    {
        yield 'zero discount' => [10_000, 0, 10_000];
        yield 'ten percent' => [10_000, 1_000, 9_000];
        yield 'fractional percentage' => [10_000, 950, 9_050];
        yield 'full discount' => [10_000, 10_000, 0];
        yield 'half minor unit rounds down' => [2, 2_501, 1];
        yield 'half minor unit rounds up' => [2, 2_500, 2];
    }

    #[DataProvider('invalidBasisPoints')]
    public function testItRejectsInvalidDiscountRates(int $basisPoints): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PercentageDiscountCalculator())->apply(Money::ofMinor(10_000, 'TRY'), $basisPoints);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidBasisPoints(): iterable
    {
        yield 'negative' => [-1];
        yield 'above one hundred percent' => [10_001];
    }

    public function testItAvoidsIntermediateOverflowAtTheMaximumMoneyValue(): void
    {
        $discounted = (new PercentageDiscountCalculator())->apply(Money::ofMinor(PHP_INT_MAX, 'TRY'), 1);

        self::assertSame(9_222_449_699_651_090_329, $discounted->minorAmount());
    }
}
