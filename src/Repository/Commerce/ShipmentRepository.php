<?php

declare(strict_types=1);

namespace App\Repository\Commerce;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Shipment;
use App\Module\Admin\Pagination\AdminPage;
use App\Module\Shipping\ShipmentState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Shipment> */
final class ShipmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Shipment::class);
    }

    public function save(Shipment $shipment): void
    {
        $this->getEntityManager()->persist($shipment);
    }

    public function findOneForOrder(CustomerOrder $order): ?Shipment
    {
        return $this->findOneBy(['order' => $order]);
    }

    /**
     * @param list<CustomerOrder> $orders
     *
     * @return array<int, Shipment|null> Keyed by order id
     */
    public function findForOrders(array $orders): array
    {
        $byOrderId = [];
        foreach ($orders as $order) {
            $byOrderId[(int) $order->id()] = null;
        }

        if ([] === $orders) {
            return $byOrderId;
        }

        /** @var list<Shipment> $shipments */
        $shipments = $this->createQueryBuilder('shipment')
            ->addSelect('customerOrder')
            ->innerJoin('shipment.order', 'customerOrder')
            ->where('customerOrder IN (:orders)')
            ->setParameter('orders', $orders)
            ->getQuery()->getResult();

        foreach ($shipments as $shipment) {
            $byOrderId[(int) $shipment->order()->id()] = $shipment;
        }

        return $byOrderId;
    }

    public function findOneForOrderNumber(string $orderNumber): ?Shipment
    {
        return $this->createQueryBuilder('shipment')
            ->innerJoin('shipment.order', 'customerOrder')
            ->andWhere('customerOrder.orderNumber = :number')->setParameter('number', trim($orderNumber))
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * One shipment under a row lock, with the identity map refreshed.
     *
     * Both halves matter. A retried create message and a carrier status callback for the same
     * parcel can arrive at once; without the lock each transaction decides "still pending" from
     * its own snapshot and the second write lands last. The refresh makes the loser see the
     * winner's committed state instead of its own stale copy.
     */
    public function findForUpdate(int $shipmentId): ?Shipment
    {
        /** @var Shipment|null $shipment */
        $shipment = $this->createQueryBuilder('shipment')
            ->andWhere('shipment.id = :id')->setParameter('id', $shipmentId)
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();

        return $shipment;
    }

    public function findOneForProviderReference(string $providerKey, string $providerReference): ?Shipment
    {
        return $this->createQueryBuilder('shipment')
            ->andWhere('shipment.providerKey = :providerKey AND shipment.providerReference = :reference')
            ->setParameter('providerKey', trim($providerKey))
            ->setParameter('reference', trim($providerReference))
            ->getQuery()->getOneOrNullResult();
    }

    /** @return AdminPage<Shipment> */
    public function adminPage(string $query, ?ShipmentState $state, int $page, int $perPage = 20): AdminPage
    {
        $builder = $this->createQueryBuilder('shipment')
            ->addSelect('customerOrder', 'customer')
            ->innerJoin('shipment.order', 'customerOrder')
            ->innerJoin('customerOrder.customer', 'customer')
            ->orderBy('shipment.createdAt', 'DESC')->addOrderBy('shipment.id', 'DESC');
        if ('' !== ($query = trim($query))) {
            // The search input reaches SQL through a bound parameter, never through a column name.
            $builder->andWhere('LOWER(customerOrder.orderNumber) LIKE :query OR LOWER(customerOrder.customerEmail) LIKE :query OR LOWER(shipment.trackingNumber) LIKE :query OR LOWER(shipment.providerReference) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }
        if (null !== $state) {
            $builder->andWhere('shipment.state = :state')->setParameter('state', $state->value);
        }
        $page = max(1, $page);
        $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($builder->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery());
        /** @var list<Shipment> $items */
        $items = iterator_to_array($paginator->getIterator(), false);

        return new AdminPage($items, $page, $perPage, count($paginator));
    }
}
