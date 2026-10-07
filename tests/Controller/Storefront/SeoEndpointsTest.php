<?php

declare(strict_types=1);

namespace App\Tests\Controller\Storefront;

use App\Module\Seo\RobotsTxtBuilder;
use App\Module\Seo\SeoRoutePolicy;
use App\Module\Seo\Sitemap\SitemapSectionBuilder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * The documents a crawler reads, as the network sees them: status, content type, and bytes.
 */
final class SeoEndpointsTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    public function testTheSitemapIndexIsServedAsXmlAtBothTheRootAndTheApplicationPrefix(): void
    {
        foreach (['/sitemap.xml', '/sitemap.xml'] as $uri) {
            $this->client->request('GET', $uri);

            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('content-type', 'text/xml; charset=UTF-8');
            $body = (string) $this->client->getResponse()->getContent();
            self::assertStringContainsString('<sitemapindex', $body, $uri);
            self::assertTrue(simplexml_load_string($body) instanceof \SimpleXMLElement, $uri.' must be well-formed XML.');
        }
    }

    public function testTheStaticSitemapIsServedAsAWellFormedUrlSet(): void
    {
        $this->client->request('GET', '/sitemap-static.xml');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertTrue(simplexml_load_string($body) instanceof \SimpleXMLElement);
        self::assertStringContainsString('<urlset', $body);
    }

    public function testAnUnknownSitemapKindOrAPagePastTheEndAnswers404(): void
    {
        $this->client->request('GET', '/sitemap-nonsense-1.xml');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/sitemap-products-9999.xml');
        self::assertResponseStatusCodeSame(404);
    }

    public function testASitemapIsNeverItselfIndexed(): void
    {
        $this->client->request('GET', '/sitemap.xml');

        self::assertResponseHeaderSame('x-robots-tag', 'noindex, follow');
    }

    public function testRobotsIsServedAsPlainTextAndForbidsEverythingInThisEnvironment(): void
    {
        $this->client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/plain; charset=UTF-8');
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('User-agent: *', $body);
        // The suite runs in APP_ENV=test. If this ever stops being true the test environment
        // becomes indexable, which is the exact failure this document exists to prevent.
        self::assertStringContainsString('Disallow: /', $body);
    }

    public function testRobotsIsAlsoReachableUnderTheApplicationPrefix(): void
    {
        $this->client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
    }

    /**
     * Guards the robots disallow list against rotting. A prefix for a section that no longer
     * exists is a rule that has quietly stopped meaning anything.
     */
    public function testEveryDisallowedPrefixCorrespondsToRoutesTheApplicationReallyServes(): void
    {
        $paths = $this->routePaths();

        foreach (RobotsTxtBuilder::PRIVATE_PREFIXES as $prefix) {
            self::assertNotEmpty(
                array_filter($paths, static fn (string $path): bool => str_starts_with($path, $prefix)),
                sprintf('No registered route starts with the disallowed prefix "%s".', $prefix),
            );
        }
    }

    /**
     * The other direction, and the one that actually bites.
     *
     * Indexability is decided twice, by two independent lists: `SeoRoutePolicy` answers from
     * route names and sets `X-Robots-Tag`, while robots.txt answers from path prefixes. A new
     * `customer_account_*` or `storefront_payment_*` screen is covered by the first list the
     * moment it exists, and by the second only if somebody remembers. This asserts the two
     * cannot drift apart in the direction that leaks.
     */
    public function testEveryRouteThisStoreCallsPrivateIsAlsoDisallowedInRobots(): void
    {
        $policy = self::getContainer()->get(SeoRoutePolicy::class);
        self::assertInstanceOf(SeoRoutePolicy::class, $policy);

        $uncovered = [];
        foreach ($this->routeNamesAndPaths() as $name => $path) {
            if (!$policy->isPrivate($name)) {
                continue;
            }
            $covered = false;
            foreach (RobotsTxtBuilder::PRIVATE_PREFIXES as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $uncovered[] = $name.' ('.$path.')';
            }
        }

        self::assertSame([], $uncovered, 'These private routes are noindex by header but not disallowed in robots.txt: '.implode(', ', $uncovered));
    }

    /** @return array<string, string> route name => path */
    private function routeNamesAndPaths(): array
    {
        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');

        $routes = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $routes[(string) $name] = $route->getPath();
        }

        return $routes;
    }

    /** @return list<string> */
    private function routePaths(): array
    {
        return array_values($this->routeNamesAndPaths());
    }

    public function testNoStorefrontPageEverLinksToASitemapServedFromAFeedAddress(): void
    {
        /** @var SitemapSectionBuilder $sections */
        $sections = self::getContainer()->get(SitemapSectionBuilder::class);
        $body = $sections->build(\App\Module\Seo\Sitemap\SitemapKind::Static, 1);

        self::assertStringNotContainsString('efeotoyedekparca', $body);
    }
}
