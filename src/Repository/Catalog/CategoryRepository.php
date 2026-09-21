<?php

namespace App\Repository\Catalog;

use App\Entity\Catalog\Category;
use App\Module\Catalog\CategoryRepositoryInterface;
use App\Module\Admin\Pagination\AdminPage;
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

    /** @return AdminPage<Category> */
    public function adminPage(string $query, int $page, int $perPage = 20): AdminPage
    {
        $builder = $this->createQueryBuilder('category')->leftJoin('category.parent', 'parent')->addSelect('parent')->orderBy('category.name', 'ASC');
        if ('' !== ($query = trim($query))) {
            $builder->andWhere('LOWER(category.name) LIKE :query OR LOWER(category.slug) LIKE :query')->setParameter('query', '%'.mb_strtolower($query).'%');
        }
        $page = max(1, $page);
        $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($builder->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery());
        /** @var list<Category> $items */
        $items = iterator_to_array($paginator->getIterator(), false);
        return new AdminPage($items, $page, $perPage, count($paginator));
    }
}
