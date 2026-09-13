<?php

namespace App\Repository\Commerce;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductPrice;
use App\Module\Pricing\ProductPriceRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductPrice>
 */
final class ProductPriceRepository extends ServiceEntityRepository implements ProductPriceRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductPrice::class);
    }

    public function findOneByProduct(Product $product): ?ProductPrice
    {
        return $this->findOneBy(['product' => $product]);
    }

    public function save(ProductPrice $price): void
    {
        $this->getEntityManager()->persist($price);
    }
}
