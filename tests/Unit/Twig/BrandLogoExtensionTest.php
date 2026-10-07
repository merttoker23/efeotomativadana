<?php

namespace App\Tests\Unit\Twig;

use App\Module\Catalog\BrandLogoStorage;
use App\Twig\BrandLogoExtension;
use App\Twig\StorefrontMediaExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\PathPackage;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\Validator\Validation;

final class BrandLogoExtensionTest extends TestCase
{
    public function testMissingBrandAlwaysUsesTheCentralAssetWithThePublicBasePath(): void
    {
        $media = new StorefrontMediaExtension(new Packages(new PathPackage('/', new EmptyVersionStrategy())), '/');
        $extension = new BrandLogoExtension(new BrandLogoStorage(sys_get_temp_dir().'/absent-brand-'.bin2hex(random_bytes(5)), Validation::createValidator()), $media);
        self::assertSame('/storefront/images/brand-placeholder.svg', $extension->logoUrl(78));
        self::assertSame('/storefront/images/brand-placeholder.svg', $extension->logoUrl(0));
    }
}
