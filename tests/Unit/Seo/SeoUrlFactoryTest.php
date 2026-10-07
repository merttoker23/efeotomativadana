<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use App\Module\Seo\SeoUrlFactory;
use App\Shared\PublicUrlGenerator;
use App\Twig\StorefrontMediaExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\PathPackage;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;

/**
 * A canonical URL is the one thing on a page that must not depend on the request, because
 * `Host` is supplied by whoever is asking. Everything here is built from DEFAULT_URI.
 */
final class SeoUrlFactoryTest extends TestCase
{
    public function testACanonicalUrlIsBuiltFromTheConfiguredBaseAndTheLocalRoute(): void
    {
        $factory = $this->factory('https://magaza.example');

        self::assertSame(
            'https://magaza.example/urun/yag-filtresi',
            $factory->absolute('storefront_catalog_product', ['slug' => 'yag-filtresi']),
        );
        self::assertSame(
            'https://magaza.example/katalog',
            $factory->absolute('storefront_catalog_index'),
        );
    }

    public function testAHostileHostHeaderCannotMoveTheCanonicalUrl(): void
    {
        $factory = $this->factory('https://magaza.example');

        $url = $factory->absolute('storefront_catalog_brand', ['slug' => 'bosch']);

        self::assertStringStartsWith('https://magaza.example/marka/bosch', $url);
        self::assertStringNotContainsString('b2b.efeotoyedekparca.com.tr', $url);
    }

    public function testStoredMediaBecomesAnAbsoluteHttpsUrlUnderThePublicBasePath(): void
    {
        $factory = $this->factory('https://magaza.example');

        self::assertSame(
            'https://magaza.example/uploads/products/filtre.jpg',
            $factory->media('/uploads/products/filtre.jpg'),
        );
    }

    public function testAMissingMediaPathYieldsNoImageRatherThanAnEmptyUrl(): void
    {
        $factory = $this->factory('https://magaza.example');

        self::assertNull($factory->media(null));
        self::assertNull($factory->media('   '));
    }

    public function testThePublicBaseIsReportedForTheSitemapAndRobotsTemplates(): void
    {
        self::assertSame('https://magaza.example', $this->factory('https://magaza.example/')->baseUri());
    }

    private function factory(string $base): SeoUrlFactory
    {
        $routes = new RouteCollection();
        $routes->add('storefront_catalog_index', new Route('/katalog'));
        $routes->add('storefront_catalog_product', new Route('/urun/{slug}'));
        $routes->add('storefront_catalog_brand', new Route('/marka/{slug}'));

        $media = new StorefrontMediaExtension(
            new Packages(new PathPackage('/', new EmptyVersionStrategy())),
            '/',
        );

        return new SeoUrlFactory(
            new PublicUrlGenerator(
                new Router(new ClosureLoader(), static fn (): RouteCollection => $routes, ['cache_dir' => null], new RequestContext()),
                $base,
            ),
            $media,
        );
    }
}
