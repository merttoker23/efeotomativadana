<?php

namespace App\Repository\Commerce;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductPrice;
use App\Module\Pricing\ProductPriceRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
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

    public function findOneByProductForUpdate(Product $product): ?ProductPrice
    {
        /** @var ProductPrice|null $price */
        $price = $this->createQueryBuilder('price')
            ->andWhere('price.product = :product')
            ->setParameter('product', $product)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $price;
    }

    public function save(ProductPrice $price): void
    {
        $this->getEntityManager()->persist($price);
    }
}
