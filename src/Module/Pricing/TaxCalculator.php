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

    private static function divideAndRoundHalfUp(int $dividend, int $divisor): int
    {
        $quotient = intdiv($dividend, $divisor);
        $remainder = $dividend % $divisor;

        return $remainder * 2 >= $divisor ? $quotient + 1 : $quotient;
    }
}
