<?php

namespace App\Module\Pricing;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductPrice;
use App\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PricingManager
{
    public function __construct(
        private ProductPriceRepositoryInterface $prices,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function upsert(
        Product $product,
        Money $basePrice,
        TaxCategory $category,
        TaxRate $rate,
        ?Money $salePrice = null,
        ?\DateTimeImmutable $saleStartsAt = null,
        ?\DateTimeImmutable $saleEndsAt = null,
    ): ProductPrice {
        if (null === $salePrice && (null !== $saleStartsAt || null !== $saleEndsAt)) {
            throw new \InvalidArgumentException('Sale bounds require a sale price.');
        }

        $candidate = new ProductPrice($product, $basePrice, $category, $rate);
        if (null !== $salePrice) {
            $candidate->scheduleSale($salePrice, $saleStartsAt, $saleEndsAt);
        }

        $price = $this->prices->findOneByProduct($product);
        if (null === $price) {
            $price = $candidate;
        } else {
            $price->clearSale();
            $price->reconfigure($basePrice, $category, $rate);
            if (null !== $salePrice) {
                $price->scheduleSale($salePrice, $saleStartsAt, $saleEndsAt);
            }
        }

        $this->prices->save($price);
        $this->entityManager->flush();

        return $price;
    }
}
