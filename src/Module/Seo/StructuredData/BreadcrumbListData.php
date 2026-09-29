<?php

declare(strict_types=1);

namespace App\Module\Seo\StructuredData;

use App\Module\Seo\SeoBreadcrumb;

/**
 * The visible path to this page, in the shape a search engine uses to replace a raw URL in a
 * result with a clickable trail.
 *
 * Positions are 1-based and assigned here rather than by the caller, so a trail can never be
 * rendered with a gap or with two steps claiming the same position. The URLs are the ones the
 * page already shows, which is what keeps the graph and the rendered navigation from
 * disagreeing.
 */
final class BreadcrumbListData
{
    /**
     * @param list<SeoBreadcrumb> $breadcrumbs
     *
     * @return array<string, mixed>
     */
    public static function for(array $breadcrumbs): array
    {
        $items = [];
        foreach ($breadcrumbs as $index => $breadcrumb) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $breadcrumb->name,
                'item' => $breadcrumb->url,
            ];
        }

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }
}
