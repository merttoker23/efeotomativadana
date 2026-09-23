<?php

namespace App\Repository\Cms;

use App\Entity\Cms\BlogPost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BlogPost> */
final class BlogPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, BlogPost::class); }

    /** @return list<BlogPost> */
    public function latestPublished(int $limit = 12): array
    {
        return $this->createQueryBuilder('post')->andWhere('post.published = true')->orderBy('post.id', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }
}
