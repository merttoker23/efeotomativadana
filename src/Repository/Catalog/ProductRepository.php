<?php

namespace App\Repository\Catalog;

use App\Entity\Catalog\Product;
use App\Module\Catalog\ProductIdentifierType;
use App\Module\Catalog\ProductRepositoryInterface;
use App\Module\Catalog\PublicationStatus;
use App\Module\Admin\Pagination\AdminPage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
final class ProductRepository extends ServiceEntityRepository implements ProductRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    public function findOneBySku(string $sku): ?Product
    {
        return $this->findOneBy(['sku' => mb_strtoupper(trim($sku))]);
    }

    public function findOneBySlug(string $slug): ?Product
    {
        return $this->findOneBy(['slug' => mb_strtolower(trim($slug))]);
    }

    public function findOnePublishedBySlug(string $slug): ?Product
    {
        return $this->findOneBy([
            'slug' => mb_strtolower(trim($slug)),
            'publicationStatus' => PublicationStatus::Published,
        ]);
    }

    /** @return list<Product> */
    public function findByIdentifier(ProductIdentifierType $type, string $code): array
    {
        /** @var list<Product> $products */
        $products = $this->createQueryBuilder('product')
            ->distinct()
            ->innerJoin('product.identifiers', 'identifier')
            ->andWhere('identifier.type = :type')
            ->andWhere('identifier.code = :code')
            ->setParameter('type', $type->value)
            ->setParameter('code', mb_strtoupper(trim($code)))
            ->orderBy('product.sku', 'ASC')
            ->getQuery()
            ->getResult();

        return $products;
    }

    public function save(Product $product): void
    {
        $this->getEntityManager()->persist($product);
    }

    /** @return AdminPage<Product> */
    public function adminPage(string $query, ?PublicationStatus $status, int $page, int $perPage = 20): AdminPage
    {
        $builder = $this->createQueryBuilder('product')
            ->leftJoin('product.brand', 'brand')->addSelect('brand')
            ->orderBy('product.updatedAt', 'DESC')->addOrderBy('product.id', 'DESC');
        if ('' !== ($query = trim($query))) {
            $builder->andWhere('LOWER(product.name) LIKE :query OR LOWER(product.sku) LIKE :query OR LOWER(product.slug) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }
        if (null !== $status) {
            $builder->andWhere('product.publicationStatus = :status')->setParameter('status', $status->value);
        }

        $page = max(1, $page);
        $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator(
            $builder->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery(),
        );

        /** @var list<Product> $items */
        $items = iterator_to_array($paginator->getIterator(), false);
        return new AdminPage($items, $page, $perPage, count($paginator));
    }
}
