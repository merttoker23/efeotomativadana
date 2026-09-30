<?php

namespace App\Repository\Cms;

use App\Entity\Cms\InformationPage;
use App\Shared\PagedResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InformationPage> */
final class InformationPageRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 20;

    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, InformationPage::class); }

    /**
     * Every page, newest first, a page at a time. The information-page list used to be an
     * unbounded `findBy`, so the storefront's own index page read the whole table.
     */
    public function page(int $page = 1, int $perPage = self::PER_PAGE, ?bool $publishedOnly = null): PagedResult
    {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);

        $builder = $this->createQueryBuilder('page');
        if (true === $publishedOnly) {
            $builder->andWhere('page.published = true');
        }
        $count = (int) (clone $builder)->select('COUNT(page.id)')->getQuery()->getSingleScalarResult();
        $items = $builder->orderBy('page.title', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()->getResult();

        return new PagedResult(array_values($items), $page, $perPage, $count);
    }
}