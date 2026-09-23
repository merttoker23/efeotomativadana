<?php

namespace App\Repository\Cms;

use App\Entity\Cms\HomeSection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<HomeSection> */
final class HomeSectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, HomeSection::class); }

    /** @return list<HomeSection> */
    public function ordered(bool $enabledOnly = false): array
    {
        $query = $this->createQueryBuilder('section')->orderBy('section.sortOrder', 'ASC')->addOrderBy('section.id', 'ASC');
        if ($enabledOnly) { $query->andWhere('section.enabled = true'); }
        return $query->getQuery()->getResult();
    }
}
