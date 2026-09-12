<?php

namespace App\Repository\Catalog;

use App\Entity\Catalog\Category;
use App\Module\Catalog\CategoryRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
final class CategoryRepository extends ServiceEntityRepository implements CategoryRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    public function findOneBySlug(string $slug): ?Category
    {
        return $this->findOneBy(['slug' => mb_strtolower(trim($slug))]);
    }

    public function save(Category $category): void
    {
        $this->getEntityManager()->persist($category);
    }
}
