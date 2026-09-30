<?php

declare(strict_types=1);

namespace App\Repository\Commerce;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderRepositoryInterface;
use App\Module\Admin\Pagination\AdminPage;
use App\Module\Order\OrderState;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
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

    public function findOneByNumberForUpdate(string $orderNumber): ?CustomerOrder
    {
        /** @var CustomerOrder|null $order */
        $order = $this->createQueryBuilder('customerOrder')
            ->andWhere('customerOrder.orderNumber = :number')->setParameter('number', trim($orderNumber))
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
        return $order;
    }

    /**
     * One customer's orders, newest first.
     *
     * The customer is a bound parameter of the query rather than a filter applied to a wider result
     * set, so a page can never be assembled from orders the reader does not own. Bounded by
     * `perPage` and ordered on the indexed `(customer_id, created_at)` pair, because an unbounded
     * account list is how a long-standing customer takes a request down.
     *
     * @return \App\Module\Order\CustomerOrderPage<CustomerOrder>
     */
    public function customerPage(CustomerUser $customer, int $page, int $perPage = 10): \App\Module\Order\CustomerOrderPage
    {
        $page = max(1, $page);
        // `OrderSummary` renders a line count and a line total per order, both of which live in
        // `items`. Fetching them here keeps the page at a fixed number of queries instead of one
        // extra query per order on the page. Paginator::count() still counts distinct roots, so
        // the page total stays the number of orders and not the number of order lines.
        $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($this->createQueryBuilder('customerOrder')
            ->addSelect('item')
            ->leftJoin('customerOrder.items', 'item')
            ->andWhere('customerOrder.customer = :customer')->setParameter('customer', $customer)
            ->orderBy('customerOrder.createdAt', 'DESC')->addOrderBy('customerOrder.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)
            ->getQuery());
        /** @var list<CustomerOrder> $items */
        $items = iterator_to_array($paginator->getIterator(), false);

        return new \App\Module\Order\CustomerOrderPage($items, $page, $perPage, count($paginator));
    }

    /** @return AdminPage<CustomerOrder> */
    public function adminPage(string $query, ?OrderState $state, int $page, int $perPage = 20): AdminPage
    {
        $builder = $this->createQueryBuilder('customerOrder')->orderBy('customerOrder.createdAt', 'DESC')->addOrderBy('customerOrder.id', 'DESC');
        if ('' !== ($query = trim($query))) {
            $builder->andWhere('LOWER(customerOrder.orderNumber) LIKE :query OR LOWER(customerOrder.customerEmail) LIKE :query OR LOWER(customerOrder.customerName) LIKE :query')->setParameter('query', '%'.mb_strtolower($query).'%');
        }
        if (null !== $state) {
            $builder->andWhere('customerOrder.state = :state')->setParameter('state', $state->value);
        }
        $page = max(1, $page);
        $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($builder->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery());
        /** @var list<CustomerOrder> $items */
        $items = iterator_to_array($paginator->getIterator(), false);
        return new AdminPage($items, $page, $perPage, count($paginator));
    }
}
