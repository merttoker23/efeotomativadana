<?php

namespace App\Twig;

use Symfony\Component\Asset\Packages;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Resolves stored public media paths to URLs the browser can actually reach.
 *
 * Uploaded media (B2B product images, CMS images) is persisted as an absolute public path
 * below the front controller, for example "/uploads/products/<file>.jpg". Symfony's asset()
 * returns such a path untouched, but the storefront is published from a directory whose
 * document root is one level above the application, so an unprefixed "/uploads/..." request
 * never reaches the application and answers 404. Prefixing with the configured public base
 * path keeps every media URL inside the application directory, which is the same mechanism
 * the theme already uses for compiled assets.
 */
final class StorefrontMediaExtension extends AbstractExtension
{
    public const PRODUCT_PLACEHOLDER = 'storefront/images/product-placeholder.svg';

    private readonly string $basePath;

    public function __construct(
        private readonly Packages $packages,
        string $assetsBasePath,
    ) {
        $basePath = rtrim(trim($assetsBasePath), '/');
        $this->basePath = '' === $basePath ? '' : '/'.ltrim($basePath, '/');
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('media_url', $this->mediaUrl(...)),
            new TwigFunction('product_image_url', $this->productImageUrl(...)),
        ];
    }

    public function mediaUrl(?string $storedPath, ?string $fallbackAsset = null): string
    {
        $path = trim((string) $storedPath);
        if ('' === $path) {
            $path = trim((string) $fallbackAsset);
            if ('' === $path) {
                throw new \InvalidArgumentException('A media URL needs either a stored path or a fallback asset.');
            }
        }
        if (!str_starts_with($path, '/')) {
            return $this->packages->getUrl($path);
        }
        if ('' === $this->basePath || str_starts_with($path, $this->basePath.'/')) {
            return $path;
        }

        return $this->basePath.$path;
    }

    public function productImageUrl(?string $storedPath): string
    {
        return $this->mediaUrl($storedPath, self::PRODUCT_PLACEHOLDER);
    }
}
