<?php

declare(strict_types=1);

namespace App\Module\Seo;

use App\Shared\PublicUrlGenerator;
use App\Twig\StorefrontMediaExtension;

/**
 * Every absolute URL the storefront publishes is built here, and always from configuration.
 *
 * The canonical link, `og:url` and the sitemap entries are the URLs a crawler stores and
 * later asks for. Deriving any of them from the incoming request would let a `Host` header,
 * or an attacker-supplied host behind a proxy, publish an address the store does not control
 * — and a canonical URL that changes per visitor is worse than no canonical URL at all.
 *
 * Media paths go through StorefrontMediaExtension rather than a second copy of the base-path
 * rule: that rule decides which prefix makes a stored "/uploads/..." file reachable at all.
 */
final readonly class SeoUrlFactory
{
    public function __construct(
        private PublicUrlGenerator $urls,
        private StorefrontMediaExtension $media,
    ) {
    }

    /**
     * The canonical URL for a local route. There is deliberately no way to pass an absolute
     * URL: the argument is a route name, so a B2B or otherwise external address can never
     * become this store's canonical.
     *
     * @param array<string, scalar> $parameters
     */
    public function absolute(string $route, array $parameters = []): string
    {
        return $this->urls->absolute($route, $parameters);
    }

    public function baseUri(): string
    {
        return $this->urls->publicBaseUri();
    }

    /**
     * A stored media path as an absolute https URL, or null when the page has no image.
     *
     * Null rather than a placeholder: `og:image` pointing at a generic "no image" asset tells
     * a crawler this product has a picture, which is a different and false statement.
     */
    public function media(?string $storedPath): ?string
    {
        $storedPath = trim((string) $storedPath);
        if ('' === $storedPath) {
            return null;
        }

        $url = $this->media->mediaUrl($storedPath);
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return $this->baseUri().('/' === $url[0] ? $url : '/'.$url);
    }
}
