<?php

declare(strict_types=1);

namespace App\Repository\Seo;

use App\Entity\Seo\SeoResourceType;
use App\Entity\Seo\SlugRedirect;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SlugRedirect>
 */
final class SlugRedirectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SlugRedirect::class);
    }

    public function findRetired(SeoResourceType $resourceType, string $oldSlug): ?SlugRedirect
    {
        return $this->findOneBy([
            'resourceType' => $resourceType,
            'oldSlug' => mb_strtolower(trim($oldSlug)),
        ]);
    }

    /**
     * Persist without flushing.
     *
     * A rename is part of a larger save that already owns its transaction — the admin
     * catalogue manager wraps product, price and stock together — and flushing here would
     * commit half of it.
     */
    public function save(SlugRedirect $redirect): void
    {
        $this->getEntityManager()->persist($redirect);
    }
}
