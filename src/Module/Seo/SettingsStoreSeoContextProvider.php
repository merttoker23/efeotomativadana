<?php

declare(strict_types=1);

namespace App\Module\Seo;

use App\Module\Settings\StoreConfiguration;

/**
 * The single place that turns admin-managed store settings into SEO facts.
 *
 * Adding a setting here rather than in a template is deliberate: a controller that read
 * `store_setting` rows itself would be a second, divergent answer to "what is the store
 * called" the moment one of them is wrong.
 */
final readonly class SettingsStoreSeoContextProvider implements StoreSeoContextProvider
{
    public function __construct(
        private StoreConfiguration $configuration,
    ) {
    }

    public function current(): StoreSeoContext
    {
        return new StoreSeoContext(
            name: $this->configuration->storeName(),
            locale: $this->configuration->defaultLocale(),
            defaultDescription: $this->configuration->seoDefaultDescription(),
            indexingEnabled: $this->configuration->isSeoIndexingEnabled(),
        );
    }
}
