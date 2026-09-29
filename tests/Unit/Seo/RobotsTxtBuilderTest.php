<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use App\Module\Seo\RobotsTxtBuilder;
use App\Module\Seo\SeoUrlFactory;
use App\Module\Seo\StoreSeoContext;
use App\Module\Seo\StoreSeoContextProvider;
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
 * A test or staging copy of a store is crawled by things nobody invited, and the canonical,
 * sitemap and structured data it serves are the real ones. robots.txt is the only place that
 * can say "do not index this environment", so getting it wrong publishes a not-yet-launched
 * catalogue to an index that cannot be un-listed quickly.
 */
final class RobotsTxtBuilderTest extends TestCase
{
    public function testANonProductionEnvironmentDisallowsEverything(): void
    {
        $robots = $this->builder('dev')->build();

        self::assertStringContainsString('User-agent: *', $robots);
        self::assertStringContainsString('Disallow: /', $robots);
        // Advertising a sitemap while forbidding every URL is a contradiction a crawler has to
        // resolve, and no version of resolving it helps.
        self::assertStringNotContainsString('Sitemap:', $robots);
    }

    public function testTheTestEnvironmentIsTreatedAsNonProduction(): void
    {
        self::assertStringContainsString('Disallow: /', $this->builder('test')->build());
    }

    public function testProductionDisallowsOnlyThePrivateAreas(): void
    {
        $robots = $this->builder('prod')->build();

        self::assertStringNotContainsString("Disallow: /\n", $robots);
        foreach (RobotsTxtBuilder::PRIVATE_PREFIXES as $prefix) {
            self::assertStringContainsString('Disallow: '.$prefix, $robots);
        }
        self::assertStringContainsString('Sitemap: https://magaza.example/yeni/sitemap.xml', $robots);
    }

    public function testTheStorefrontItselfIsNeverDisallowedInProduction(): void
    {
        $robots = $this->builder('prod')->build();

        self::assertStringNotContainsString('Disallow: /yeni/katalog', $robots);
        self::assertStringNotContainsString('Disallow: /yeni/urun', $robots);
        self::assertStringNotContainsString('Disallow: /yeni/blog', $robots);
    }

    public function testAStoreThatHasNotLaunchedCanCloseItselfFromItsOwnSettings(): void
    {
        $robots = $this->builder('prod', indexingEnabled: false)->build();

        self::assertStringContainsString('Disallow: /', $robots);
        self::assertStringNotContainsString('Sitemap:', $robots);
    }

    private function builder(string $environment, bool $indexingEnabled = true): RobotsTxtBuilder
    {
        return new RobotsTxtBuilder(
            new class($indexingEnabled) implements StoreSeoContextProvider {
                public function __construct(private readonly bool $indexingEnabled) {}

                public function current(): StoreSeoContext
                {
                    return new StoreSeoContext('Efe Otomotiv Adana', 'tr', '', $this->indexingEnabled);
                }
            },
            $this->urls(),
            $environment,
        );
    }

    private function urls(): SeoUrlFactory
    {
        $routes = new RouteCollection();
        $routes->add('storefront_sitemap_index', new Route('/yeni/sitemap.xml'));

        return new SeoUrlFactory(
            new PublicUrlGenerator(
                new Router(new ClosureLoader(), static fn (): RouteCollection => $routes, ['cache_dir' => null], new RequestContext()),
                'https://magaza.example',
            ),
            new StorefrontMediaExtension(
                new Packages(new PathPackage('/yeni', new EmptyVersionStrategy())),
                '/yeni',
            ),
        );
    }
}
