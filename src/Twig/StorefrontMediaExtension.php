<?php

namespace App\Twig;

use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;
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
final class StorefrontMediaExtension extends AbstractExtension implements ResetInterface
{
    public const PRODUCT_PLACEHOLDER = 'storefront/images/product-placeholder.svg';

    private readonly string $basePath;

    /** @var array<string, array{width: int, height: int}|null> */
    private array $dimensions = [];

    public function __construct(
        private readonly Packages $packages,
        string $assetsBasePath,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir = '',
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
            new TwigFunction('image_dimensions', $this->imageDimensions(...)),
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

    /** @return array{width: int, height: int}|null */
    public function imageDimensions(?string $storedPath): ?array
    {
        $path = trim((string) $storedPath);
        $path = '' === $path ? self::PRODUCT_PLACEHOLDER : $path;
        if ('' !== $this->basePath && str_starts_with($path, $this->basePath.'/')) {
            $path = substr($path, strlen($this->basePath));
        }
        if (array_key_exists($path, $this->dimensions)) {
            return $this->dimensions[$path];
        }
        $this->dimensions[$path] = null;
        // Dimensions are read only from application-managed uploads and shipped theme assets.
        // This helper never fetches a remote image or resolves a client-supplied filesystem path.
        if ('' === $this->projectDir || str_contains($path, '..')) {
            return null;
        }
        if (preg_match('~\A/uploads/(?:products|cms)/[A-Za-z0-9_-]+\.(?:jpe?g|png|webp)\z~i', $path)) {
            $file = $this->projectDir.'/public'.$path;
        } elseif (preg_match('~\Astorefront/images/[A-Za-z0-9_/-]+\.(?:svg|jpe?g|png|webp)\z~i', $path)) {
            $file = $this->projectDir.'/assets/'.$path;
        } else {
            return null;
        }
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        if (str_ends_with(strtolower($file), '.svg')) {
            // SVG is accepted only from checked-in assets. Parse the root's intrinsic sizing,
            // without an XML parser or any possibility of loading external entities.
            $svg = file_get_contents($file);
            if (false === $svg || !preg_match('/<svg\b([^>]+)>/i', $svg, $root)) {
                return null;
            }
            if (preg_match('/\bwidth=["\x27](\d+)["\x27]/', $root[1], $width) && preg_match('/\bheight=["\x27](\d+)["\x27]/', $root[1], $height)) {
                $size = [(int) $width[1], (int) $height[1]];
            } elseif (preg_match('/\bviewBox=["\x27]0 0 (\d+) (\d+)["\x27]/', $root[1], $viewBox)) {
                $size = [(int) $viewBox[1], (int) $viewBox[2]];
            } else {
                return null;
            }
        } else {
            $size = @getimagesize($file);
        }
        if (false === $size || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        return $this->dimensions[$path] = ['width' => $size[0], 'height' => $size[1]];
    }

    public function reset(): void
    {
        $this->dimensions = [];
    }
}
