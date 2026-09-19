<?php

namespace App\Shared;

use App\Module\Catalog\Query\CatalogQuery;
use App\Module\Settings\StoreConfiguration;

final readonly class StorefrontPageContext
{
    public function __construct(
        private CatalogQuery $catalog,
        private StoreConfiguration $configuration,
    ) {
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public function withLayout(array $values = []): array
    {
        return $values + [
            'store' => [
                'name' => $this->configuration->storeName(),
                'locale' => $this->configuration->defaultLocale(),
            ],
            'catalog_navigation' => [
                'categories' => $this->catalog->categories(8),
                'brands' => $this->catalog->brands(8),
            ],
        ];
    }
}
