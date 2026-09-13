<?php

namespace App\Repository\Commerce;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductInventory;
use App\Module\Inventory\ProductInventoryRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductInventory>
 */
final class ProductInventoryRepository extends ServiceEntityRepository implements ProductInventoryRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductInventory::class);
    }

    public function findOneByProduct(Product $product): ?ProductInventory
    {
        return $this->findOneBy(['product' => $product]);
    }

    public function findOneByProductForUpdate(Product $product): ?ProductInventory
    {
        /** @var ProductInventory|null $inventory */
        $inventory = $this->createQueryBuilder('inventory')
            ->andWhere('inventory.product = :product')
            ->setParameter('product', $product)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $inventory;
    }

    public function save(ProductInventory $inventory): void
    {
        $this->getEntityManager()->persist($inventory);
    }
}
