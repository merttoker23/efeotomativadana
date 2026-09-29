<?php

declare(strict_types=1);

namespace App\Module\Seo;

use App\Entity\Seo\SeoResourceType;
use App\Entity\Seo\SlugRedirect;
use App\Repository\Seo\SlugRedirectRepository;
use Doctrine\DBAL\Connection;

/**
 * Answers "this address used to be live — where does it go now?".
 *
 * The target is re-read from the live record on every request rather than stored, because a
 * stored target is a copy that goes stale the moment the resource is renamed again, and a
 * stale copy is a redirect that lands on another 404. Nothing is answered for a record that
 * has since been deleted or unpublished: sending a crawler from one 404 to another wastes its
 * time and tells it this store's redirects do not work.
 */
final readonly class SlugRedirectResolver
{
    public function __construct(
        private SlugRedirectRepository $redirects,
        private Connection $connection,
        private SeoUrlFactory $urls,
    ) {
    }

    public function find(SeoResourceType $resourceType, string $oldSlug): ?SlugRedirect
    {
        return $this->redirects->findRetired($resourceType, $oldSlug);
    }

    /**
     * The absolute URL this retired address should now send a visitor to, or null when there
     * is nothing live to send them to.
     */
    public function targetUrlFor(SlugRedirect $redirect): ?string
    {
        $slug = $this->connection->fetchOne(
            sprintf('SELECT slug FROM %s WHERE id = ? AND %s', $redirect->resourceType()->table(), $redirect->resourceType()->publishedCondition()),
            [$redirect->resourceId()],
        );

        if (false === $slug || !is_string($slug) || '' === $slug) {
            return null;
        }

        return $this->urls->absolute($redirect->resourceType()->routeName(), ['slug' => $slug]);
    }
}
