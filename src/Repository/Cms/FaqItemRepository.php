<?php

declare(strict_types=1);

namespace App\Repository\Cms;

use App\Entity\Cms\FaqItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FaqItem> */
final class FaqItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FaqItem::class);
    }

    /** @return list<FaqItem> */
    public function ordered(bool $activeOnly = false): array
    {
        $builder = $this->createQueryBuilder('faq')
            ->orderBy('faq.sortOrder', 'ASC')
            ->addOrderBy('faq.id', 'ASC');

        if ($activeOnly) {
            $builder->andWhere('faq.active = true');
        }

        return $builder->getQuery()->getResult();
    }

    /** @return list<FaqItem> */
    public function activeOrdered(): array
    {
        return $this->ordered(true);
    }

    public function nextSortOrder(): int
    {
        $maximum = $this->createQueryBuilder('faq')
            ->select('MAX(faq.sortOrder)')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $maximum ? 0 : ((int) $maximum + 10);
    }
}
