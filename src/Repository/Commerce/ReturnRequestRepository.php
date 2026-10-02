<?php

declare(strict_types=1);

namespace App\Repository\Commerce;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\OrderItem;
use App\Entity\Commerce\ReturnRequest;
use App\Entity\Customer\CustomerUser;
use App\Module\Admin\Pagination\AdminPage;
use App\Module\Returns\ReturnState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ReturnRequest> */
final class ReturnRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReturnRequest::class);
    }

    public function save(ReturnRequest $return): void
    {
        $this->getEntityManager()->persist($return);
    }

    /**
     * A return is addressed by its own number *and* its customer.
     *
     * Both are in the query rather than checked in PHP, so guessing a return number returns the
     * same "not found" as guessing a nonexistent one and cannot be told apart by timing the answer.
     */
    public function findOneByNumberForCustomer(string $returnNumber, CustomerUser $customer): ?ReturnRequest
    {
        return $this->findOneBy(['returnNumber' => trim($returnNumber), 'customer' => $customer]);
    }

    public function findOneForUpdate(int $id): ?ReturnRequest
    {
        /** @var ReturnRequest|null $return */
        $return = $this->createQueryBuilder('returnRequest')
            ->andWhere('returnRequest.id = :id')->setParameter('id', $id)
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();

        return $return;
    }

    /**
     * The order's non-terminal returns, newest first.
     *
     * A rejected or withdrawn request releases the quantity it was holding, so it must not be
     * counted when deciding how much of an order is still returnable.
     *
     * @return list<ReturnRequest>
     */
    public function findActiveForOrder(CustomerOrder $order): array
    {
        /** @var list<ReturnRequest> $rows */
        $rows = $this->createQueryBuilder('returnRequest')
            ->andWhere('returnRequest.order = :order')->setParameter('order', $order)
            ->andWhere('returnRequest.state NOT IN (:closed)')
            ->setParameter('closed', [ReturnState::Rejected->value, ReturnState::Withdrawn->value])
            ->orderBy('returnRequest.createdAt', 'DESC')->addOrderBy('returnRequest.id', 'DESC')
            ->getQuery()->getResult();

        return $rows;
    }

    /**
     * How many units of one order line are already spoken for by an open or approved return.
     *
     * Returned as a per-line map so a single query answers the whole order rather than one query
     * per line, which is what makes a 20-line order's form load in one round trip.
     *
     * @return array<int, int> order item id => claimed quantity
     */
    public function claimedQuantitiesForOrder(CustomerOrder $order): array
    {
        /** @var list<array{order_item_id: string, claimed: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT ri.order_item_id, SUM(ri.quantity) AS claimed FROM commerce_return_request_item ri INNER JOIN commerce_return_request r ON r.id = ri.return_request_id WHERE r.order_id = ? AND r.state NOT IN (?, ?) GROUP BY ri.order_item_id',
            [(int) $order->id(), ReturnState::Rejected->value, ReturnState::Withdrawn->value],
        );

        $claimed = [];
        foreach ($rows as $row) {
            $claimed[(int) $row['order_item_id']] = (int) $row['claimed'];
        }

        return $claimed;
    }

    /**
     * The order line a return's own line refers to, or null when the id belongs to another order.
     *
     * Never resolved through the return's order, so a hand-posted `order_item_id` from a different
     * order cannot be smuggled into a request and refunded.
     */
    public function findOrderItemOf(CustomerOrder $order, int $orderItemId): ?OrderItem
    {
        return $this->getEntityManager()->getRepository(OrderItem::class)->findOneBy(['id' => $orderItemId, 'order' => $order]);
    }

    /**
     * One customer's returns, newest first.
     *
     * The customer is a bound parameter of the query rather than a filter applied afterwards, so a
     * page can never be assembled from a wider result set than it should have seen.
     *
     * @return \App\Module\Returns\ReturnPage<ReturnRequest>
     */
    public function customerPage(CustomerUser $customer, int $page, int $perPage = 10): \App\Module\Returns\ReturnPage
    {
        $page = max(1, $page);
        // The list renders the order number and the summed line total for every row, and both
        // live on associations: `orderNumber()` proxies the order and `totalValue()` sums the
        // items. Without these joins each row cost two more queries, so a ten-row page spent
        // twenty of its queries proving what the page was about to show.
        $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($this->createQueryBuilder('returnRequest')
            ->addSelect('customerOrder', 'item')
            ->leftJoin('returnRequest.order', 'customerOrder')
            ->leftJoin('returnRequest.items', 'item')
            ->andWhere('returnRequest.customer = :customer')->setParameter('customer', $customer)
            ->orderBy('returnRequest.createdAt', 'DESC')->addOrderBy('returnRequest.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)
            ->getQuery());
        /** @var list<ReturnRequest> $items */
        $items = iterator_to_array($paginator->getIterator(), false);

        return new \App\Module\Returns\ReturnPage($items, $page, $perPage, count($paginator));
    }

    /** @return AdminPage<ReturnRequest> */
    public function adminPage(string $query, ?ReturnState $state, int $page, int $perPage = 20): AdminPage
    {
        // Same two associations the admin list renders per row; see customerPage().
        $builder = $this->createQueryBuilder('returnRequest')
            ->addSelect('customerOrder', 'item')
            ->leftJoin('returnRequest.order', 'customerOrder')
            ->leftJoin('returnRequest.items', 'item')
            ->orderBy('returnRequest.createdAt', 'DESC')->addOrderBy('returnRequest.id', 'DESC');
        if ('' !== ($query = trim($query))) {
            $builder->andWhere('LOWER(returnRequest.returnNumber) LIKE :query OR LOWER(returnRequest.customerReason) LIKE :query OR LOWER(customerOrder.orderNumber) LIKE :query')->setParameter('query', '%'.mb_strtolower($query).'%');
        }
        if (null !== $state) {
            $builder->andWhere('returnRequest.state = :state')->setParameter('state', $state->value);
        }
        $page = max(1, $page);
        $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($builder->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery());
        /** @var list<ReturnRequest> $items */
        $items = iterator_to_array($paginator->getIterator(), false);

        return new AdminPage($items, $page, $perPage, count($paginator));
    }
}
