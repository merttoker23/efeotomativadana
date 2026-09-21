<?php

namespace App\Repository\Catalog;

use App\Entity\Catalog\Brand;
use App\Module\Catalog\BrandRepositoryInterface;
use App\Module\Admin\Pagination\AdminPage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Brand>
 */
final class BrandRepository extends ServiceEntityRepository implements BrandRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Brand::class);
    }

    public function findOneBySlug(string $slug): ?Brand
    {
        return $this->findOneBy(['slug' => mb_strtolower(trim($slug))]);
    }

    public function save(Brand $brand): void
    {
        $this->getEntityManager()->persist($brand);
    }

    /** @return AdminPage<Brand> */
    public function adminPage(string $query, int $page, int $perPage = 20): AdminPage
    {
        $builder = $this->createQueryBuilder('brand')->orderBy('brand.name', 'ASC');
        if ('' !== ($query = trim($query))) {
            $builder->andWhere('LOWER(brand.name) LIKE :query OR LOWER(brand.slug) LIKE :query')->setParameter('query', '%'.mb_strtolower($query).'%');
        }
        $page = max(1, $page);
        $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($builder->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery());
        /** @var list<Brand> $items */
        $items = iterator_to_array($paginator->getIterator(), false);
        return new AdminPage($items, $page, $perPage, count($paginator));
    }
}
