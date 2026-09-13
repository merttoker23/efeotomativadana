<?php

namespace App\Module\Pricing;

use App\Shared\Money\Money;

final readonly class TaxBreakdown
{
    public function __construct(
        private Money $gross,
        private Money $net,
        private Money $tax,
    ) {
        if ($gross->currency() !== $net->currency() || $gross->currency() !== $tax->currency()) {
            throw new \InvalidArgumentException('Tax breakdown monies must use the same currency.');
        }

        if (!$gross->equals($net->add($tax))) {
            throw new \InvalidArgumentException('Tax breakdown gross must equal net plus tax.');
        }
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
