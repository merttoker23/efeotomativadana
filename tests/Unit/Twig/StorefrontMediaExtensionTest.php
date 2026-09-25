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
        $extension = $this->extension('/yeni');

        self::assertSame(
            '/yeni/uploads/products/abc.jpg',
            $extension->productImageUrl('/uploads/products/abc.jpg'),
        );
        self::assertSame(
            '/yeni/uploads/cms/abc.webp',
            $extension->mediaUrl('/uploads/cms/abc.webp'),
        );
    }

    public function testAnAlreadyPrefixedPathIsNotPrefixedTwice(): void
    {
        $extension = $this->extension('/yeni/');

        self::assertSame(
            '/yeni/uploads/products/abc.jpg',
            $extension->mediaUrl('/yeni/uploads/products/abc.jpg'),
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
        $extension = $this->extension('/yeni');

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
        $extension = $this->extension('/yeni');

        self::assertSame(
            '/storefront/images/hero-automotive.svg',
            $extension->mediaUrl('storefront/images/hero-automotive.svg'),
        );
    }

    public function testAFallbackWithoutAnyPathIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->extension('/yeni')->mediaUrl(null);
    }

    private function extension(string $basePath): StorefrontMediaExtension
    {
        return new StorefrontMediaExtension(
            new Packages(new PathPackage('', new EmptyVersionStrategy())),
            $basePath,
        );
    }
}
