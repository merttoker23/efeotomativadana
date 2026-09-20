<?php

declare(strict_types=1);

namespace App\Repository\Commerce;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CustomerOrder> */
final class CustomerOrderRepository extends ServiceEntityRepository implements OrderRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerOrder::class);
    }

    public function save(CustomerOrder $order): void
    {
        $this->getEntityManager()->persist($order);
    }

    public function findOneByNumberForCustomer(string $orderNumber, CustomerUser $customer): ?CustomerOrder
    {
        return $this->findOneBy(['orderNumber' => $orderNumber, 'customer' => $customer]);
    }
}
