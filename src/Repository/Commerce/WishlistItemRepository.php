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

    public function findForCustomer(CustomerUser $customer): array
    {
        return $this->findBy(['customer' => $customer], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    public function save(WishlistItem $item): void
    {
        $this->getEntityManager()->persist($item);
    }

    public function remove(WishlistItem $item): void
    {
        $this->getEntityManager()->remove($item);
    }
}
