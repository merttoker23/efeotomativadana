<?php

declare(strict_types=1);

namespace App\Repository\Loyalty;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Customer\CustomerUser;
use App\Entity\Loyalty\RewardTransaction;
use App\Module\Admin\Pagination\AdminPage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RewardTransaction> */
final class RewardTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RewardTransaction::class);
    }

    public function sourceForUpdate(string $sourceKey): ?RewardTransaction
    {
        return $this->createQueryBuilder('entry')->where('entry.sourceKey = :source')->setParameter('source', $sourceKey)
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
    }

    public function balance(CustomerUser $customer, bool $forUpdate = false): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(points), 0) FROM loyalty_reward_transaction WHERE customer_id = ?'.($forUpdate ? ' FOR UPDATE' : ''),
            [$customer->id()],
        );
    }

    public function reversedForUpdate(CustomerOrder $order): int
    {
        return -(int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COALESCE(SUM(points), 0) FROM loyalty_reward_transaction WHERE order_id = ? AND kind = 'REVERSAL' FOR UPDATE",
            [$order->id()],
        );
    }

    /** @return AdminPage<RewardTransaction> */
    public function page(?CustomerUser $customer, int $requestedPage): AdminPage
    {
        $qb = $this->createQueryBuilder('entry');
        if (null !== $customer) {
            $qb->where('entry.customer = :customer')->setParameter('customer', $customer);
        }
        $total = (int) (clone $qb)->select('COUNT(entry.id)')->getQuery()->getSingleScalarResult();
        $page = max(1, min($requestedPage, max(1, (int) ceil($total / 25))));
        /** @var list<RewardTransaction> $items */
        $items = $qb->addSelect('customer', 'customerOrder')->join('entry.customer', 'customer')
            ->leftJoin('entry.order', 'customerOrder')->orderBy('entry.createdAt', 'DESC')->addOrderBy('entry.id', 'DESC')
            ->setFirstResult(($page - 1) * 25)->setMaxResults(25)->getQuery()->getResult();
        return new AdminPage($items, $page, 25, $total);
    }
}
