<?php

namespace App\Module\Pricing;

use App\Entity\Catalog\Product;
use Psr\Clock\ClockInterface;

final readonly class PricingQuery
{
    public function __construct(
        private ProductPriceRepositoryInterface $prices,
        private TaxCalculator $taxCalculator,
        private ClockInterface $clock,
    ) {
    }

    public function forProduct(Product $product): ?PriceView
    {
        $price = $this->prices->findOneByProduct($product);
        if (null === $price) {
            return null;
        }

        $now = $this->clock->now();
        $sellPrice = $price->sellPriceAt($now);
        $tax = $this->taxCalculator->fromTaxInclusive($sellPrice, $price->taxRate());

        return new PriceView(
            $price->basePrice(),
            $sellPrice,
            $tax->net(),
            $tax->tax(),
            $price->taxCategory(),
            $price->taxRate(),
            $price->isSaleActiveAt($now),
        );
    }
}
