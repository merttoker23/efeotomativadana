<?php

namespace App\Module\Pricing;

use App\Shared\Money\Money;

final readonly class LineTotals
{
    private int $quantity;

    public function __construct(
        private Money $unitGross,
        mixed $quantity,
        private Money $gross,
        private Money $net,
        private Money $tax,
    ) {
        if (!is_int($quantity) || $quantity <= 0) {
            throw new \InvalidArgumentException('Line quantity must be a positive integer.');
        }

        if (
            $unitGross->currency() !== $gross->currency()
            || $unitGross->currency() !== $net->currency()
            || $unitGross->currency() !== $tax->currency()
        ) {
            throw new \InvalidArgumentException('Line totals monies must use the same currency.');
        }

        if (!$unitGross->multiply($quantity)->equals($gross)) {
            throw new \InvalidArgumentException('Line totals gross must equal unit gross multiplied by quantity.');
        }

        if (!$gross->equals($net->add($tax))) {
            throw new \InvalidArgumentException('Line totals gross must equal net plus tax.');
        }

        $this->quantity = $quantity;
    }

    public function unitGross(): Money
    {
        return $this->unitGross;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function gross(): Money
    {
        return $this->gross;
    }

    public function net(): Money
    {
        return $this->net;
    }

    public function tax(): Money
    {
        return $this->tax;
    }
}
