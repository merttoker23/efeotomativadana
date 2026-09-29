<?php

declare(strict_types=1);

namespace App\Module\Seo\StructuredData;

use App\Module\Seo\StoreSeoContext;

/**
 * Identifies the storefront itself, on every page.
 *
 * A search engine that has never seen this store before needs one node that says what the
 * site is and where it lives; the same graph is also where a merchant's own details would go
 * once the settings model grows them. `url` is the configured base rather than the request's
 * host, for the same reason every other absolute URL here is.
 */
final class OrganizationData
{
    /**
     * @return array<string, mixed>
     */
    public static function for(StoreSeoContext $store, string $baseUri): array
    {
        return [
            '@type' => 'Organization',
            'name' => $store->name,
            'url' => $baseUri,
            'inLanguage' => $store->locale,
        ];
    }
}
