<?php

namespace App\Repository\Commerce;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\WishlistItem;
use App\Entity\Customer\CustomerUser;
use App\Module\Cart\WishlistRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WishlistItem> */
final class WishlistItemRepository extends ServiceEntityRepository implements WishlistRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WishlistItem::class);
    }

    public function findOne(CustomerUser $customer, Product $product): ?WishlistItem
    {
        return $this->findOneBy(['customer' => $customer, 'product' => $product]);
    }

    public function findOwnedById(CustomerUser $customer, int $id): ?WishlistItem
    {
        return $this->findOneBy(['customer' => $customer, 'id' => $id]);
    }

    /**
     * Bounded and ordered. `ORDER BY created_at DESC` needs `(customer_id, created_at)` to avoid
     * sorting the customer's whole list in memory, and without a page size a customer who saved
     * a few hundred parts made the page read every one of them.
     *
     * @return list<WishlistItem>
     */
    public function findForCustomer(CustomerUser $customer, int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);

        return array_values($this->createQueryBuilder('item')
            ->addSelect('product')
            ->innerJoin('item.product', 'product')
            ->where('item.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('item.createdAt', 'DESC')
            ->addOrderBy('item.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()->getResult());
    }

    public function save(WishlistItem $item): void
    {
        $this->getEntityManager()->persist($item);
    }

    public function countForCustomer(CustomerUser $customer): int
    {
        return $this->count(['customer' => $customer]);
    }

    public function remove(WishlistItem $item): void
    {
        $this->getEntityManager()->remove($item);
    }
}
