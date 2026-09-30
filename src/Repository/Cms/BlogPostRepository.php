<?php

namespace App\Repository\Cms;

use App\Entity\Cms\BlogPost;
use App\Shared\PagedResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BlogPost> */
final class BlogPostRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 20;

    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, BlogPost::class); }

    /** @return list<BlogPost> */
    public function latestPublished(int $limit = 12): array
    {
        return $this->createQueryBuilder('post')->andWhere('post.published = true')->orderBy('post.id', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }

    /**
     * Every post, newest first, a page at a time.
     *
     * The admin list used to `findBy([], ['id' => 'DESC'])`, which renders the entire table on
     * one screen and holds every entity in memory to do it. The public blog list had the same
     * shape through the storefront.
     */
    public function page(int $page = 1, int $perPage = self::PER_PAGE, ?bool $publishedOnly = null): PagedResult
    {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);

        $builder = $this->createQueryBuilder('post');
        if (true === $publishedOnly) {
            $builder->andWhere('post.published = true');
        }
        $count = (int) (clone $builder)->select('COUNT(post.id)')->getQuery()->getSingleScalarResult();
        $items = $builder->orderBy('post.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()->getResult();

        return new PagedResult(array_values($items), $page, $perPage, $count);
    }
}