<?php

namespace App\Repository\Integration;

use App\Entity\Integration\B2bSyncObservation;
use App\Module\Integration\B2b\B2bResourceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<B2bSyncObservation>
 */
final class B2bSyncObservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, B2bSyncObservation::class);
    }

    /**
     * @param list<string> $externalIds
     * @return list<B2bSyncObservation>
     */
    public function findByRunAndExternalIds(int $runId, string $providerKey, B2bResourceType $resourceType, array $externalIds): array
    {
        $externalIds = array_values(array_unique(array_filter(array_map('trim', $externalIds), static fn (string $id): bool => '' !== $id)));
        if ([] === $externalIds) {
            return [];
        }

        /** @var list<B2bSyncObservation> $observations */
        $observations = $this->createQueryBuilder('observation')
            ->andWhere('IDENTITY(observation.run) = :runId')
            ->andWhere('observation.providerKey = :provider')
            ->andWhere('observation.resourceType = :type')
            ->andWhere('observation.externalId IN (:ids)')
            ->setParameter('runId', $runId)
            ->setParameter('provider', mb_strtolower(trim($providerKey)))
            ->setParameter('type', $resourceType->value)
            ->setParameter('ids', $externalIds)
            ->orderBy('observation.externalId', 'ASC')
            ->getQuery()
            ->getResult();

        return $observations;
    }

    /**
     * @param list<string> $skus
     * @return list<B2bSyncObservation>
     */
    public function findSkuObservationsForRun(int $runId, string $providerKey, array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map(static fn (string $sku): string => mb_strtoupper(trim($sku)), $skus), static fn (string $sku): bool => '' !== $sku)));
        if ([] === $skus) {
            return [];
        }

        /** @var list<B2bSyncObservation> $observations */
        $observations = $this->createQueryBuilder('observation')
            ->andWhere('IDENTITY(observation.run) = :runId')
            ->andWhere('observation.providerKey = :provider')
            ->andWhere('observation.resourceType = :type')
            ->andWhere('observation.sku IN (:skus)')
            ->setParameter('runId', $runId)
            ->setParameter('provider', mb_strtolower(trim($providerKey)))
            ->setParameter('type', B2bResourceType::Product->value)
            ->setParameter('skus', $skus)
            ->orderBy('observation.sku', 'ASC')
            ->addOrderBy('observation.externalId', 'ASC')
            ->getQuery()
            ->getResult();

        return $observations;
    }

    public function save(B2bSyncObservation $observation): void
    {
        $this->getEntityManager()->persist($observation);
    }

    public function deleteForRun(int $runId): void
    {
        if ($runId < 1) {
            throw new \InvalidArgumentException('B2B run ID must be positive.');
        }
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM integration_b2b_sync_observation WHERE run_id = ?',
            [$runId],
        );
    }
}
