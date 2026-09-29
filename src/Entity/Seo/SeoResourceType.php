<?php

declare(strict_types=1);

namespace App\Entity\Seo;

/**
 * The content types that own a public URL, and therefore own a slug that can be retired.
 *
 * This enum is the single registry of that mapping, and it is deliberately the *only* place
 * a table name appears for redirect resolution. A resolver that interpolated a table name
 * built from a request value would be an injection point; a `match` over a closed enum whose
 * arms are constants is a whitelist, and keeping it here means adding a content type is one
 * visible case rather than a hunt through the query layer.
 */
enum SeoResourceType: string
{
    case Product = 'product';
    case Category = 'category';
    case Brand = 'brand';
    case BlogPost = 'blog_post';
    case InformationPage = 'information_page';

    public function routeName(): string
    {
        return match ($this) {
            self::Product => 'storefront_catalog_product',
            self::Category => 'storefront_catalog_category',
            self::Brand => 'storefront_catalog_brand',
            self::BlogPost => 'storefront_blog_show',
            self::InformationPage => 'storefront_information_show',
        };
    }

    public function table(): string
    {
        return match ($this) {
            self::Product => 'catalog_product',
            self::Category => 'catalog_category',
            self::Brand => 'catalog_brand',
            self::BlogPost => 'cms_blog_post',
            self::InformationPage => 'cms_information_page',
        };
    }

    /**
     * The SQL fragment that identifies a row as publicly reachable.
     *
     * Catalogue records carry `PublicationStatus`, CMS records carry a `published` boolean.
     * Both are asserted here so a redirect can never hand a crawler the URL of a draft.
     */
    public function publishedCondition(): string
    {
        return match ($this) {
            self::Product, self::Category, self::Brand => "publication_status = 'published'",
            self::BlogPost, self::InformationPage => 'published = 1',
        };
    }
}
