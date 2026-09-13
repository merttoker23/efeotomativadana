<?php

namespace App\Module\Pricing;

use App\Shared\Money\Money;

final readonly class PriceView
{
    public function __construct(
        private Money $basePrice,
        private Money $sellPrice,
        private Money $netPrice,
        private Money $taxAmount,
        private TaxCategory $taxCategory,
        private TaxRate $taxRate,
        private bool $onSale,
    ) {
        $currency = $sellPrice->currency();
        if (
            $basePrice->currency() !== $currency
            || $netPrice->currency() !== $currency
            || $taxAmount->currency() !== $currency
        ) {
            throw new \InvalidArgumentException('Price view monies must use the same currency.');
        }

        if (!$sellPrice->subtract($netPrice)->equals($taxAmount)) {
            throw new \InvalidArgumentException('Price view sell price must equal net price plus tax.');
        }
    }

    public function basePrice(): Money
    {
        return $this->basePrice;
    }

    public function sellPrice(): Money
    {
        return $this->sellPrice;
    }

    public function netPrice(): Money
    {
        return $this->netPrice;
    }

    public function taxAmount(): Money
    {
        return $this->taxAmount;
    }

    public function taxCategory(): TaxCategory
    {
        return $this->taxCategory;
    }

    public function taxRate(): TaxRate
    {
        return $this->taxRate;
    }

    public function onSale(): bool
    {
        return $this->onSale;
    }
}
