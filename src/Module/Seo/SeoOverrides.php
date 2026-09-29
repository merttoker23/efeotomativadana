<?php

declare(strict_types=1);

namespace App\Module\Seo;

/**
 * The merchant's own corrections to a page's metadata.
 *
 * Every field is optional and every field wins over what the page could derive. That is the
 * point: a merchant must be able to fix one awkward product title without being made to fill
 * in the other two, and a store with no overrides at all must still produce complete metadata.
 *
 * `noIndex` is a nullable bool rather than a plain flag so that "the merchant did not say"
 * stays distinguishable from "the merchant said yes", which is the difference between a page
 * that is indexable and one that is hidden.
 */
final readonly class SeoOverrides
{
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?bool $noIndex = null,
    ) {
    }
}
