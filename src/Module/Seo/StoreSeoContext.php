<?php

declare(strict_types=1);

namespace App\Module\Seo;

/**
 * The store-wide facts every page's metadata falls back to, read from the admin-managed
 * settings.
 *
 * Passed in through a provider rather than injected: `StoreConfiguration` reads the database,
 * and a metadata factory that is only pure when its inputs are pure is one that will stop
 * being pure. `defaultDescription` is nullable because the store may genuinely have none set,
 * and the metadata factory has its own final fallback for that — inventing a sentence the
 * merchant did not write is not this application's job.
 */
final readonly class StoreSeoContext
{
    public function __construct(
        public string $name,
        public string $locale,
        public ?string $defaultDescription = null,
        public bool $indexingEnabled = true,
    ) {
    }
}
