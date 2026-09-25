<?php

namespace App\Module\Pricing;

use App\Shared\Money\Money;

final class PercentageDiscountCalculator
{
    public function apply(Money $amount, int $discountBasisPoints): Money
    {
        if ($discountBasisPoints < 0 || $discountBasisPoints > 10000) {
            throw new \InvalidArgumentException('Discount must be between 0 and 100 percent.');
        }

        $factor = 10000 - $discountBasisPoints;
        if (0 === $factor) {
            return Money::ofMinor(0, $amount->currency());
        }

        $wholeUnits = intdiv($amount->minorAmount(), 10000);
        $remainder = $amount->minorAmount() % 10000;
        if ($wholeUnits > intdiv(PHP_INT_MAX, $factor)) {
            throw new \OverflowException('Percentage discount calculation exceeds the integer range.');
        }

        $minorAmount = ($wholeUnits * $factor) + self::divideAndRoundHalfUp($remainder * $factor, 10000);

        return Money::ofMinor($minorAmount, $amount->currency());
    }

    private static function divideAndRoundHalfUp(int $dividend, int $divisor): int
    {
        $quotient = intdiv($dividend, $divisor);
        $remainder = $dividend % $divisor;

        return $remainder * 2 >= $divisor ? $quotient + 1 : $quotient;
    }
}
