<?php

namespace App\Repository\Integration;

use App\Entity\Integration\ExternalResourceMapping;
use App\Module\Integration\B2b\B2bResourceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExternalResourceMapping>
 */
final class ExternalResourceMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalResourceMapping::class);
    }

    public function findOneByExternalId(string $providerKey, B2bResourceType $resourceType, string $externalId): ?ExternalResourceMapping
    {
        return $this->findOneBy([
            'providerKey' => mb_strtolower(trim($providerKey)),
            'resourceType' => $resourceType,
            'externalId' => trim($externalId),
        ]);
    }

    /**
     * @param list<string> $externalIds
     * @return list<ExternalResourceMapping>
     */
    public function findByExternalIds(string $providerKey, B2bResourceType $resourceType, array $externalIds): array
    {
        $externalIds = array_values(array_unique(array_filter(array_map('trim', $externalIds), static fn (string $id): bool => '' !== $id)));
        if ([] === $externalIds) {
            return [];
        }

        /** @var list<ExternalResourceMapping> $mappings */
        $mappings = $this->createQueryBuilder('mapping')
            ->andWhere('mapping.providerKey = :provider')
            ->andWhere('mapping.resourceType = :type')
            ->andWhere('mapping.externalId IN (:ids)')
            ->setParameter('provider', mb_strtolower(trim($providerKey)))
            ->setParameter('type', $resourceType->value)
            ->setParameter('ids', $externalIds)
            ->orderBy('mapping.externalId', 'ASC')
            ->getQuery()
            ->getResult();

        return $mappings;
    }

    /**
     * @return list<int>
     */
    public function findProductIdsNotSeenPage(string $providerKey, int $runId, int $afterId, int $limit = 250): array
    {
        if ($runId < 1 || $afterId < 0 || $limit < 1) {
            throw new \InvalidArgumentException('B2B reconciliation paging parameters are invalid.');
        }

        $connection = $this->getEntityManager()->getConnection();
        $ids = $connection->fetchFirstColumn(
            <<<'SQL'
                SELECT local_resource_id
                FROM integration_external_mapping
                WHERE provider_key = :provider
                  AND resource_type = :type
                  AND (last_seen_run_id IS NULL OR last_seen_run_id <> :run_id)
                  AND CAST(local_resource_id AS UNSIGNED) > :after_id
                ORDER BY CAST(local_resource_id AS UNSIGNED) ASC
                LIMIT :page_limit
                SQL,
            [
                'provider' => mb_strtolower(trim($providerKey)),
                'type' => B2bResourceType::Product->value,
                'run_id' => $runId,
                'after_id' => $afterId,
                'page_limit' => $limit,
            ],
            [
                'provider' => ParameterType::STRING,
                'type' => ParameterType::STRING,
                'run_id' => ParameterType::INTEGER,
                'after_id' => ParameterType::INTEGER,
                'page_limit' => ParameterType::INTEGER,
            ],
        );

        return array_map('intval', $ids);
    }

    /** @return list<int> */
    public function findProductIdsNotSeen(string $providerKey, int $runId): array
    {
        if ($runId < 1) {
            throw new \InvalidArgumentException('Run ID must be a positive integer.');
        }

        /** @var list<string> $ids */
        $ids = $this->createQueryBuilder('mapping')
            ->select('mapping.localResourceId')
            ->andWhere('mapping.providerKey = :provider')
            ->andWhere('mapping.resourceType = :type')
            ->andWhere('(mapping.lastSeenRunId IS NULL OR mapping.lastSeenRunId <> :runId)')
            ->setParameter('provider', mb_strtolower(trim($providerKey)))
            ->setParameter('type', B2bResourceType::Product->value)
            ->setParameter('runId', $runId)
            ->orderBy('mapping.localResourceId', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $ids);
    }

    public function save(ExternalResourceMapping $mapping): void
    {
        $this->getEntityManager()->persist($mapping);
    }
}
