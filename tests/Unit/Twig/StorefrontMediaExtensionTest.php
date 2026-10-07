<?php

namespace App\Tests\Unit\Twig;

use App\Twig\StorefrontMediaExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\PathPackage;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;

final class StorefrontMediaExtensionTest extends TestCase
{
    public function testStoredAbsoluteMediaPathsAreServedFromTheApplicationDirectory(): void
    {
        $extension = $this->extension('/subdir');

        self::assertSame(
            '/subdir/uploads/products/abc.jpg',
            $extension->productImageUrl('/uploads/products/abc.jpg'),
        );
        self::assertSame(
            '/subdir/uploads/cms/abc.webp',
            $extension->mediaUrl('/uploads/cms/abc.webp'),
        );
    }

    public function testAnAlreadyPrefixedPathIsNotPrefixedTwice(): void
    {
        $extension = $this->extension('/subdir/');

        self::assertSame(
            '/subdir/uploads/products/abc.jpg',
            $extension->mediaUrl('/subdir/uploads/products/abc.jpg'),
        );
    }

    public function testWithoutAPublicBasePathTheStoredPathIsUsedAsIs(): void
    {
        self::assertSame(
            '/uploads/products/abc.jpg',
            $this->extension('')->mediaUrl('/uploads/products/abc.jpg'),
        );
        self::assertSame(
            '/uploads/products/abc.jpg',
            $this->extension('/')->mediaUrl('/uploads/products/abc.jpg'),
        );
    }

    public function testAMissingProductImageFallsBackToTheAutomotivePlaceholder(): void
    {
        $extension = $this->extension('/subdir');

        self::assertSame(
            '/storefront/images/product-placeholder.svg',
            $extension->productImageUrl(null),
        );
        self::assertSame(
            '/storefront/images/product-placeholder.svg',
            $extension->productImageUrl('   '),
        );
    }

    public function testLogicalAssetNamesResolveThroughTheAssetPackage(): void
    {
        $extension = $this->extension('/subdir');

        self::assertSame(
            '/storefront/images/hero-automotive.svg',
            $extension->mediaUrl('storefront/images/hero-automotive.svg'),
        );
    }

    public function testAFallbackWithoutAnyPathIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->extension('/subdir')->mediaUrl(null);
    }

    public function testImageDimensionsUseTheRealLocalMediaRatherThanAnInventedSquare(): void
    {
        $extension = $this->extension('/subdir');
        self::assertSame(['width' => 400, 'height' => 320], $extension->imageDimensions(null));
        self::assertSame(['width' => 640, 'height' => 640], $extension->imageDimensions('storefront/images/hero-automotive.svg'));
    }

    public function testUploadedRasterDimensionsAreReadOnceAndUnsafeOrMissingPathsReturnNoDimensions(): void
    {
        $directory = sys_get_temp_dir().'/media-dimensions-'.bin2hex(random_bytes(6));
        mkdir($directory.'/public/uploads/products', 0777, true);
        $image = $directory.'/public/uploads/products/example.png';
        file_put_contents($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jFz0AAAAASUVORK5CYII=', true));
        $extension = new StorefrontMediaExtension(new Packages(new PathPackage('', new EmptyVersionStrategy())), '/subdir', $directory);
        try {
            self::assertSame(['width' => 1, 'height' => 1], $extension->imageDimensions('/subdir/uploads/products/example.png'));
            unlink($image);
            self::assertSame(['width' => 1, 'height' => 1], $extension->imageDimensions('/subdir/uploads/products/example.png'), 'Repeated images reuse metadata within the request.');
            $extension->reset();
            self::assertNull($extension->imageDimensions('/subdir/uploads/products/example.png'), 'A new request must not retain stale image metadata.');
            foreach (['https://example.com/image.png', '/uploads/../example.png', '/uploads/products/missing.jpg', '/etc/passwd'] as $path) {
                self::assertNull($extension->imageDimensions($path));
            }
        } finally {
            if (is_file($image)) {
                unlink($image);
            }
            rmdir($directory.'/public/uploads/products');
            rmdir($directory.'/public/uploads');
            rmdir($directory.'/public');
            rmdir($directory);
        }
    }

    private function extension(string $basePath): StorefrontMediaExtension
    {
        return new StorefrontMediaExtension(
            new Packages(new PathPackage('', new EmptyVersionStrategy())),
            $basePath,
            dirname(__DIR__, 3),
        );
    }
}
