<?php

declare(strict_types=1);

namespace App\Module\Seo;

/**
 * One step of the visible path a visitor took to reach this page.
 *
 * Stored as a resolved absolute URL rather than a route name: the breadcrumb is rendered
 * into the page and into the BreadcrumbList graph, and both must show the same address the
 * canonical link shows, not a second URL derived by a different route.
 */
final readonly class SeoBreadcrumb
{
    public function __construct(
        public string $name,
        public string $url,
    ) {
    }
}
