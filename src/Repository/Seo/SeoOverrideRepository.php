<?php

declare(strict_types=1);

namespace App\Repository\Seo;

use App\Entity\Seo\SeoOverride;
use App\Entity\Seo\SeoResourceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeoOverride>
 */
final class SeoOverrideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeoOverride::class);
    }

    public function findFor(SeoResourceType $resourceType, int $resourceId): ?SeoOverride
    {
        return $this->findOneBy(['resourceType' => $resourceType, 'resourceId' => $resourceId]);
    }
}
