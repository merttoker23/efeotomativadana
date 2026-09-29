<?php

declare(strict_types=1);

namespace App\Module\Seo;

use App\Module\Settings\StoreConfiguration;

/**
 * Supplies the store-wide facts that every page's metadata falls back to.
 *
 * An interface rather than a direct `StoreConfiguration` dependency because those facts are
 * request-time data read from the database, not constructor arguments. Reading them once when
 * the factory is built would freeze the store's name, locale and indexing switch into the
 * container for the lifetime of the process — wrong the moment the merchant renames the store,
 * and wrong outright in the long-running Messenger worker.
 */
interface StoreSeoContextProvider
{
    public function current(): StoreSeoContext;
}
