<?php

namespace App\Module\Pricing;

use App\Shared\Money\Money;

final class TaxCalculator
{
    public function fromTaxInclusive(Money $gross, TaxRate $rate): TaxBreakdown
    {
        $divisor = 10000 + $rate->basisPoints();
        $wholeGrossUnits = intdiv($gross->minorAmount(), $divisor);
        $grossRemainder = $gross->minorAmount() % $divisor;

        $netMinorAmount = ($wholeGrossUnits * 10000) + self::divideAndRoundHalfUp($grossRemainder * 10000, $divisor);
        $net = Money::ofMinor($netMinorAmount, $gross->currency());

        return new TaxBreakdown($gross, $net, $gross->subtract($net));
    }

    public function fromTaxExclusive(Money $net, TaxRate $rate): TaxBreakdown
    {
        $factor = 10000 + $rate->basisPoints();
        $wholeNetUnits = intdiv($net->minorAmount(), 10000);
        $netRemainder = $net->minorAmount() % 10000;
        if ($wholeNetUnits > intdiv(PHP_INT_MAX, $factor)) {
            throw new \OverflowException('Tax-exclusive gross conversion exceeds the integer range.');
        }

        $wholeGrossAmount = $wholeNetUnits * $factor;
        $roundedRemainder = self::divideAndRoundHalfUp($netRemainder * $factor, 10000);
        if ($wholeGrossAmount > PHP_INT_MAX - $roundedRemainder) {
            throw new \OverflowException('Tax-exclusive gross conversion exceeds the integer range.');
        }
        $gross = Money::ofMinor($wholeGrossAmount + $roundedRemainder, $net->currency());

        return new TaxBreakdown($gross, $net, $gross->subtract($net));
    }

    private static function divideAndRoundHalfUp(int $dividend, int $divisor): int
    {
        $quotient = intdiv($dividend, $divisor);
        $remainder = $dividend % $divisor;

        return $remainder * 2 >= $divisor ? $quotient + 1 : $quotient;
    }
}
