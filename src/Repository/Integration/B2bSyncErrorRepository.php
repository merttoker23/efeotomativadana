<?php

namespace App\Repository\Integration;

use App\Entity\Integration\B2bSyncError;
use App\Module\Admin\Pagination\AdminPage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<B2bSyncError>
 */
final class B2bSyncErrorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, B2bSyncError::class);
    }

    public function countForRun(int $runId): int
    {
        return (int) $this->createQueryBuilder('error')
            ->select('COUNT(error.id)')
            ->andWhere('IDENTITY(error.run) = :runId')
            ->setParameter('runId', $runId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return AdminPage<B2bSyncError> */
    public function adminPageForRun(int $runId, int $page, int $perPage = 20): AdminPage
    {
        $page = max(1, $page);
        $paginator = new Paginator($this->createQueryBuilder('error')
            ->andWhere('IDENTITY(error.run) = :runId')
            ->setParameter('runId', $runId)
            ->orderBy('error.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery());

        /** @var list<B2bSyncError> $items */
        $items = iterator_to_array($paginator->getIterator(), false);

        return new AdminPage($items, $page, $perPage, count($paginator));
    }

    public function save(B2bSyncError $error): void
    {
        $this->getEntityManager()->persist($error);
    }
}
