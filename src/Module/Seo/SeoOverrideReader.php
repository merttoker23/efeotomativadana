<?php

declare(strict_types=1);

namespace App\Module\Seo;

use App\Entity\Seo\SeoResourceType;
use App\Repository\Seo\SeoOverrideRepository;

/**
 * The reader every storefront controller uses instead of touching the override table.
 *
 * It always returns a value, never null, so no caller has to ask "did somebody write an
 * override for this?" before it can build metadata. That question is the kind of thing one
 * controller answers and the next forgets, and the page that forgot it would quietly publish
 * the merchant's replacement title on some routes and not on others.
 */
final readonly class SeoOverrideReader
{
    public function __construct(
        private SeoOverrideRepository $overrides,
    ) {
    }

    public function for(SeoResourceType $resourceType, int $resourceId): SeoOverrides
    {
        return $this->overrides->findFor($resourceType, $resourceId)?->overrides() ?? new SeoOverrides();
    }
}
