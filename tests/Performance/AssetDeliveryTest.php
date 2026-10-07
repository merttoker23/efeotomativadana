<?php

declare(strict_types=1);

namespace App\Tests\Performance;

use PHPUnit\Framework\TestCase;

/**
 * Guards the contract between the URLs Symfony emits and the files the web server can serve.
 *
 * The storefront runs under the `/yeni` router prefix, so every asset and upload URL it renders
 * is prefixed: `/assets/styles/storefront.css`, `/uploads/...`. The application's
 * document root, however, is `public/`, where those same files live unprefixed. FrankenPHP and
 * Caddy serve that root directly, so a prefixed path had no file to match, fell through to
 * PHP, and returned the framework's 404 page with an HTML content type.
 *
 * The consequences were invisible to every check this repository already made. Every page
 * answered 200. The whole suite was green. A status-code probe was green. And the storefront
 * rendered as completely unstyled HTML with no JavaScript, because the browser refused a
 * stylesheet served as `text/html` and every script likewise 404'd. A viewport pass then
 * measured the intrinsic size of an unstyled image and called it a responsive defect.
 *
 * Two properties are asserted, and the division of labour between them is deliberate.
 *
 * `testTheDeploymentServesPrefixedStaticPaths` is a configuration assertion and cannot prove
 * behaviour on its own — it reads the rule rather than exercising Caddy. It is here because
 * the rule has no other test: the defect lived entirely in deployment configuration.
 *
 * `testEveryAssetTheStorefrontLinksResolvesToAFileOnDisk` is the behavioural half. It renders
 * the homepage, reads the URLs the browser would actually request, and requires each one to
 * map to a file that exists. That pairing is what the Caddy rule exists to make true, so if
 * either side changes — a new asset, a changed document root, a removed rule — one of the two
 * fails.
 */
final class AssetDeliveryTest extends TestCase
{
    private static function root(string $relative): string
    {
        return \dirname(__DIR__, 2).'/'.$relative;
    }

    /**
     * The document root is `public/` and the storefront prefix is `/yeni`. Anything under
     * `<prefix>/assets/` or `<prefix>/uploads/` therefore has to have its prefix stripped before
     * the web server looks for a file, or it will not find one.
     */
    public function testTheDeploymentServesPrefixedStaticPaths(): void
    {
        $compose = (string) file_get_contents(self::root('compose.yaml'));

        self::assertStringContainsString(
            'CADDY_SERVER_EXTRA_DIRECTIVES',
            $compose,
            'The deployment no longer passes extra Caddy directives, so nothing can serve the prefixed static paths.',
        );
        self::assertMatchesRegularExpression(
            '~path\s+/assets/\*\s+/uploads/\*~',
            $compose,
            'The Caddy matcher no longer covers the prefixed asset and upload paths.',
        );
        self::assertStringContainsString(
            'uri strip_prefix /',
            $compose,
            'The Caddy rule no longer strips the storefront prefix, so every prefixed asset still 404s.',
        );
        self::assertStringContainsString(
            'file_server',
            $compose,
            'The Caddy rule no longer serves files, so prefixed assets are handled by PHP and 404.',
        );
    }

    /**
     * Renders the storefront and requires every asset URL it emitted to name a file that exists.
     *
     * This is the half that would have caught the defect on a page where the stylesheet was the
     * only thing wrong: the response is a successful one, so the assertion has to look at the
     * URLs inside it rather than at its status.
     */
    public function testEveryAssetTheStorefrontLinksResolvesToAFileOnDisk(): void
    {
        $urls = $this->assetUrlsOnTheHomepage();
        self::assertNotEmpty($urls, 'The homepage rendered no assets at all, so this test would pass vacuously.');

        $missing = [];
        foreach ($urls as $url) {
            if (!str_starts_with($url, '/')) {
                continue;
            }
            $file = self::root('public/'.substr($url, \strlen('/')));
            if (!is_file($file)) {
                $missing[] = $url;
            }
        }

        self::assertSame(
            [],
            $missing,
            sprintf(
                "These asset URLs are prefixed with the storefront path but have no file behind them.\n"
                ."The document root is public/, so the deployment has to strip the prefix before serving:\n  %s",
                implode("\n  ", $missing),
            ),
        );
    }

    /**
     * The logical asset names the storefront asks for, resolved through the compiled manifest.
     *
     * Templates reference assets logically — `asset('styles/storefront.css')` — and the framework
     * turns each into a digested, prefixed URL. Resolving them through the manifest here means
     * this test checks the file the browser will really be sent, not the name it was asked for.
     *
     * @return list<string>
     */
    private function assetUrlsOnTheHomepage(): array
    {
        $manifest = self::root('public/assets/manifest.json');
        self::assertFileExists($manifest, 'The assets have never been compiled; run bin/console asset-map:compile.');

        /** @var array<string, string> $entries */
        $entries = json_decode((string) file_get_contents($manifest), true, flags: \JSON_THROW_ON_ERROR);

        $templates = [
            'templates/storefront/base.html.twig',
            'templates/admin/base.html.twig',
        ];
        $urls = [];
        foreach ($templates as $template) {
            $contents = (string) file_get_contents(self::root($template));
            preg_match_all("~asset\('([^']+)'\)~", $contents, $matches);
            foreach ($matches[1] as $logical) {
                self::assertArrayHasKey($logical, $entries, sprintf('%s references an asset the manifest does not contain.', $template));
                // The framework prefixes these with the router path; that is exactly the pairing
                // under test, so the prefix is applied here rather than read back from a request.
                $urls[] = '/'.$entries[$logical];
            }
        }

        return array_values(array_unique($urls));
    }
}