<?php

namespace App\Module\Pricing;

use App\Shared\Money\Money;

final class LineTotalCalculator
{
    public function calculate(Money $unitGross, TaxRate $rate, mixed $quantity): LineTotals
    {
        if (!is_int($quantity) || $quantity <= 0) {
            throw new \InvalidArgumentException('Line quantity must be a positive integer.');
        }

        $gross = $unitGross->multiply($quantity);
        $breakdown = (new TaxCalculator())->fromTaxInclusive($gross, $rate);

        return new LineTotals(
            $unitGross,
            $quantity,
            $breakdown->gross(),
            $breakdown->net(),
            $breakdown->tax(),
        );
    }
}
