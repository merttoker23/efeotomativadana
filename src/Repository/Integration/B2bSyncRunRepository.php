<?php

namespace App\Repository\Integration;

use App\Entity\Integration\B2bSyncRun;
use App\Module\Admin\Pagination\AdminPage;
use App\Module\Integration\B2b\B2bSyncMode;
use App\Module\Integration\B2b\B2bSyncState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<B2bSyncRun>
 */
final class B2bSyncRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, B2bSyncRun::class);
    }

    public function findActive(string $providerKey): ?B2bSyncRun
    {
        return $this->findOneBy(['activeProviderKey' => mb_strtolower(trim($providerKey))]);
    }

    public function latestSuccessful(string $providerKey, B2bSyncMode $mode): ?B2bSyncRun
    {
        return $this->findOneBy([
            'providerKey' => mb_strtolower(trim($providerKey)),
            'mode' => $mode,
            'state' => B2bSyncState::Completed,
        ], ['finishedAt' => 'DESC', 'id' => 'DESC']);
    }

    /** @return AdminPage<B2bSyncRun> */
    public function adminPage(int $page, int $perPage = 20): AdminPage
    {
        $page = max(1, $page);
        $paginator = new Paginator($this->createQueryBuilder('run')
            ->orderBy('run.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery());

        /** @var list<B2bSyncRun> $items */
        $items = iterator_to_array($paginator->getIterator(), false);

        return new AdminPage($items, $page, $perPage, count($paginator));
    }

    public function save(B2bSyncRun $run): void
    {
        $this->getEntityManager()->persist($run);
    }
}
