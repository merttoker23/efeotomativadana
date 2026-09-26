<?php

declare(strict_types=1);

namespace App\Repository\Commerce;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Payment;
use App\Entity\Commerce\PaymentAttempt;
use App\Module\Admin\Pagination\AdminPage;
use App\Module\Payment\PaymentState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Payment> */
final class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    public function save(Payment $payment): void
    {
        $this->getEntityManager()->persist($payment);
    }

    public function findOneForOrder(CustomerOrder $order): ?Payment
    {
        return $this->findOneBy(['order' => $order]);
    }

    public function findOneForOrderNumber(string $orderNumber): ?Payment
    {
        return $this->createQueryBuilder('payment')
            ->innerJoin('payment.order', 'customerOrder')
            ->andWhere('customerOrder.orderNumber = :number')->setParameter('number', trim($orderNumber))
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * The order's payment under a row lock.
     *
     * `HINT_REFRESH` matters as much as the lock here: a second checkout attempt for the same
     * order must see the first one's committed payment instead of deciding from a stale copy
     * that no payment exists and trying to insert a second one.
     */
    public function findOneForUpdate(CustomerOrder $order): ?Payment
    {
        /** @var Payment|null $payment */
        $payment = $this->createQueryBuilder('payment')
            ->andWhere('payment.order = :order')->setParameter('order', $order)
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();

        return $payment;
    }

    /**
     * The single entry point a provider callback is allowed to address. Matching on the
     * unguessable return token keeps a replayed or forged callback from reaching an attempt
     * that does not belong to it.
     */
    public function findAttemptByReturnToken(string $returnToken): ?PaymentAttempt
    {
        return $this->attempts()->findOneBy(['returnToken' => trim($returnToken)]);
    }

    /**
     * The same attempt, re-read under a row lock with the identity map refreshed.
     *
     *
     * A browser redirect and a server-to-server webhook for one payment can arrive at the
     * same moment. Without this lock both transactions decide "the attempt is still open"
     * from their own snapshot and the second write lands last, which can leave a payment
     * marked cancelled while real money is captured against it. Locking serialises the two,
     * and the refresh makes the loser see the winner's committed state.
     */
    public function lockAttemptByReturnToken(string $returnToken): ?PaymentAttempt
    {
        /** @var PaymentAttempt|null $attempt */
        $attempt = $this->attempts()->createQueryBuilder('attempt')
            ->andWhere('attempt.returnToken = :token')->setParameter('token', trim($returnToken))
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();

        return $attempt;
    }

    public function findAttemptByIdempotencyKey(string $idempotencyKey): ?PaymentAttempt
    {
        return $this->attempts()->findOneBy(['idempotencyKey' => trim($idempotencyKey)]);
    }

    public function findAttemptByProviderReference(string $providerKey, string $providerReference): ?PaymentAttempt
    {
        return $this->attempts()->findOneBy([
            'providerKey' => trim($providerKey),
            'providerReference' => trim($providerReference),
        ]);
    }

    /** @return EntityRepository<PaymentAttempt> */
    private function attempts(): EntityRepository
    {
        return $this->getEntityManager()->getRepository(PaymentAttempt::class);
    }

    /** @return AdminPage<Payment> */
    public function adminPage(string $query, ?PaymentState $state, int $page, int $perPage = 20): AdminPage
    {
        $builder = $this->createQueryBuilder('payment')
            ->addSelect('customerOrder', 'customer')
            ->innerJoin('payment.order', 'customerOrder')
            ->innerJoin('customerOrder.customer', 'customer')
            ->orderBy('payment.createdAt', 'DESC')->addOrderBy('payment.id', 'DESC');
        if ('' !== ($query = trim($query))) {
            $builder->andWhere('LOWER(customerOrder.orderNumber) LIKE :query OR LOWER(customerOrder.customerEmail) LIKE :query OR LOWER(payment.providerReference) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }
        if (null !== $state) {
            $builder->andWhere('payment.state = :state')->setParameter('state', $state->value);
        }
        $page = max(1, $page);
        $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($builder->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery());
        /** @var list<Payment> $items */
        $items = iterator_to_array($paginator->getIterator(), false);

        return new AdminPage($items, $page, $perPage, count($paginator));
    }
}
