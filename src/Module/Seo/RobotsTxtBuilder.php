<?php

declare(strict_types=1);

namespace App\Module\Seo;

/**
 * Renders robots.txt.
 *
 * Two independent reasons to forbid crawling, and both are honoured here rather than being
 * left to a deployment detail nobody remembers:
 *
 *  - the environment. A dev, test or staging copy serves the real canonical URLs, the real
 *    sitemap and real structured data; indexing it publishes a catalogue the merchant has not
 *    launched, from an address that competes with the real one. Only `prod` is indexable.
 *  - the store's own switch, so a store that is not ready can close itself from settings
 *    instead of from a code change.
 *
 * The private areas are disallowed by prefix rather than merely being kept out of the sitemap.
 * A sitemap omission is a suggestion; a disallow is a rule, and a customer's order-confirmation
 * URL should not be a page a crawler can find and index.
 */
final readonly class RobotsTxtBuilder
{
    /**
     * Paths that must never be crawled, all under the storefront's own prefix.
     *
     * Only paths that exist in production are listed. The dev-only profiler and error routes
     * are absent on purpose: they are not registered in a production environment at all, and a
     * rule for a path this deployment never serves is a rule that has quietly stopped meaning
     * anything. Dev and test are already covered by `Disallow: /`.
     *
     * This list and `SeoRoutePolicy` answer the same question twice, from paths and from route
     * names, so they have to agree in both directions. `SeoEndpointsTest` asserts each entry
     * here matches a real route, and that every route the policy calls private is disallowed
     * here — the second direction is the one that leaks.
     */
    public const PRIVATE_PREFIXES = [
        '/yeni/admin',
        '/yeni/hesabim',
        '/yeni/odeme',
        '/yeni/siparis',
        '/yeni/sepet',
        '/yeni/karsilastir',
        '/yeni/istek-listem',
        '/yeni/giris',
        '/yeni/cikis',
        '/yeni/kayit',
        '/yeni/parolami-unuttum',
        '/yeni/parola-sifirla',
    ];

    public function __construct(
        private StoreSeoContextProvider $store,
        private SeoUrlFactory $urls,
        private string $environment,
    ) {
    }

    public function build(): string
    {
        if (!$this->indexingAllowed()) {
            return "User-agent: *\nDisallow: /\n";
        }

        $lines = ['User-agent: *'];
        foreach (self::PRIVATE_PREFIXES as $prefix) {
            $lines[] = 'Disallow: '.$prefix;
        }
        $lines[] = '';
        $lines[] = 'Sitemap: '.$this->urls->absolute('storefront_sitemap_index');

        return implode("\n", $lines)."\n";
    }

    private function indexingAllowed(): bool
    {
        return 'prod' === strtolower(trim($this->environment))
            && $this->store->current()->indexingEnabled;
    }
}
