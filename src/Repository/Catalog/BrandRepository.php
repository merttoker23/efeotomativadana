<?php

namespace App\Repository\Catalog;

use App\Entity\Catalog\Brand;
use App\Module\Catalog\BrandRepositoryInterface;
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
}
