<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use App\Module\Seo\SeoBreadcrumb;
use App\Module\Seo\SeoMetadataFactory;
use App\Module\Seo\SeoOverrides;
use App\Module\Seo\SeoPage;
use App\Module\Seo\SeoText;
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
 * Every primary public content type has to produce a deterministic title, description and
 * canonical URL with no field the merchant must fill in. These tests pin the fallback order
 * that makes that true: an admin override wins, then the page's own facts, then the store.
 */
final class SeoMetadataFactoryTest extends TestCase
{
    public function testTheTitleFallsBackFromThePageLabelToTheStoreName(): void
    {
        $factory = $this->factory();

        self::assertSame('Yag Filtresi | Efe Otomotiv Adana', $factory->for($this->page(label: 'Yag Filtresi'))->title);
        self::assertSame('Efe Otomotiv Adana', $factory->for($this->page())->title);
    }

    public function testAnAdminTitleOverrideIsUsedVerbatimWithoutTheStoreSuffix(): void
    {
        $metadata = $this->factory()->for($this->page(
            label: 'Yag Filtresi',
            overrides: new SeoOverrides(title: 'MANN HWK 11/2'),
        ));

        self::assertSame('MANN HWK 11/2', $metadata->title);
    }

    public function testTheDescriptionFallsBackFromThePagesOwnProseToTheStoreDefault(): void
    {
        $factory = $this->factory();

        self::assertSame(
            'Yag filtresi degisim periyodu 10.000 km.',
            $factory->for($this->page(text: '<p>Yag filtresi degisim periyodu 10.000&nbsp;km.</p>'))->description,
        );
        // A category has no prose of its own, so the store default is used rather than nothing.
        self::assertSame('Adana yedek parça mağazası.', $factory->for($this->page())->description);
    }

    public function testTheStoreNameIsTheLastResortSoADescriptionIsNeverEmpty(): void
    {
        $metadata = $this->factory($this->store(defaultDescription: ''))->for($this->page());

        self::assertSame('Efe Otomotiv Adana', $metadata->description);
    }

    public function testAnAdminDescriptionOverrideIsUsedVerbatim(): void
    {
        $metadata = $this->factory()->for($this->page(
            text: '<p>Ignored because the merchant wrote their own.</p>',
            overrides: new SeoOverrides(description: 'Yedek parça doğrudan Adana deposundan.'),
        ));

        self::assertSame('Yedek parça doğrudan Adana deposundan.', $metadata->description);
    }

    public function testTheCanonicalUrlComesFromTheLocalRouteAndNotFromThePage(): void
    {
        $metadata = $this->factory()->for($this->page(
            route: 'storefront_catalog_product',
            routeParameters: ['slug' => 'yag-filtresi'],
            label: 'Yag Filtresi',
        ));

        self::assertSame('https://magaza.example/yeni/urun/yag-filtresi', $metadata->canonicalUrl);
    }

    public function testAPageIsIndexableByDefaultAndHiddenWhenThePageOrAnOverrideSaysSo(): void
    {
        $factory = $this->factory();

        self::assertTrue($factory->for($this->page())->indexable);
        self::assertFalse($factory->for($this->page(noIndex: true))->indexable);
        self::assertFalse($factory->for($this->page(overrides: new SeoOverrides(noIndex: true)))->indexable);
    }

    public function testAStoreWideIndexingSwitchHidesEverythingEvenWhenThePageSaysOtherwise(): void
    {
        $metadata = $this->factory($this->store(indexingEnabled: false))->for($this->page());

        self::assertFalse($metadata->indexable);
    }

    public function testOpenGraphBasicsMirrorTheResolvedMetadata(): void
    {
        $metadata = $this->factory()->for($this->page(
            label: 'Yag Filtresi',
            type: 'article',
            imagePath: '/uploads/products/filtre.jpg',
        ));

        self::assertSame('article', $metadata->type);
        self::assertSame('https://magaza.example/yeni/uploads/products/filtre.jpg', $metadata->imageUrl);
        self::assertSame('Efe Otomotiv Adana', $metadata->siteName);
    }

    public function testAPageWithBreadcrumbsRendersOrganizationAndBreadcrumbListJsonLd(): void
    {
        $metadata = $this->factory()->for($this->page(
            label: 'Yag Filtresi',
            breadcrumbs: [
                new SeoBreadcrumb('Ana Sayfa', 'https://magaza.example/yeni/'),
                new SeoBreadcrumb('Yag Filtresi', 'https://magaza.example/yeni/urun/yag-filtresi'),
            ],
        ));

        self::assertCount(2, $metadata->breadcrumbs);
        self::assertCount(2, $metadata->jsonLd);
        self::assertStringContainsString('"@type":"Organization"', $metadata->jsonLd[0]);
        self::assertStringContainsString('"@type":"BreadcrumbList"', $metadata->jsonLd[1]);
        self::assertStringContainsString('"position":2', $metadata->jsonLd[1]);
    }

    public function testAHomePageCarriesNoBreadcrumbList(): void
    {
        $metadata = $this->factory()->for(SeoPage::home());

        self::assertSame([], $metadata->breadcrumbs);
        self::assertCount(1, $metadata->jsonLd);
    }

    public function testATrailOfOnlyTheHomePageIsNotAdvertisedAsAHierarchy(): void
    {
        $metadata = $this->factory()->for($this->page(
            label: 'Markalar',
            breadcrumbs: [new SeoBreadcrumb('Ana Sayfa', 'https://magaza.example/yeni/')],
        ));

        // A consumer that believes a one-step trail would render a breadcrumb that goes nowhere.
        self::assertCount(1, $metadata->jsonLd);
        self::assertStringNotContainsString('BreadcrumbList', $metadata->jsonLd[0]);
    }

    public function testAnIndexablePageEmitsNoRobotsDirectiveAtAll(): void
    {
        self::assertNull($this->factory()->for($this->page())->robots);
    }

    public function testAPageThatSaysItMustNotBeIndexedGetsTheDirective(): void
    {
        self::assertSame('noindex, follow', $this->factory()->for($this->page(noIndex: true))->robots);
    }

    /**
     * @param array<string, scalar>              $routeParameters
     * @param list<\App\Module\Seo\SeoBreadcrumb> $breadcrumbs
     */
    private function page(
        string $route = 'storefront_catalog_index',
        array $routeParameters = [],
        ?string $label = null,
        ?string $text = null,
        ?SeoOverrides $overrides = null,
        ?string $imagePath = null,
        string $type = 'website',
        bool $noIndex = false,
        array $breadcrumbs = [],
    ): SeoPage {
        return new SeoPage(
            route: $route,
            routeParameters: $routeParameters,
            label: $label,
            text: $text,
            overrides: $overrides,
            imagePath: $imagePath,
            type: $type,
            noIndex: $noIndex,
            breadcrumbs: $breadcrumbs,
        );
    }

    private function store(string $defaultDescription = 'Adana yedek parça mağazası.', bool $indexingEnabled = true): StoreSeoContext
    {
        return new StoreSeoContext(
            name: 'Efe Otomotiv Adana',
            locale: 'tr',
            defaultDescription: $defaultDescription,
            indexingEnabled: $indexingEnabled,
        );
    }

    private function factory(?StoreSeoContext $store = null): SeoMetadataFactory
    {
        return new SeoMetadataFactory(
            $this->urls(),
            new SeoText(),
            new class($store ?? $this->store()) implements StoreSeoContextProvider {
                public function __construct(private readonly StoreSeoContext $context) {}

                public function current(): StoreSeoContext
                {
                    return $this->context;
                }
            },
        );
    }

    private function urls(): SeoUrlFactory
    {
        $routes = new RouteCollection();
        $routes->add('storefront_catalog_index', new Route('/yeni/katalog'));
        $routes->add('storefront_catalog_product', new Route('/yeni/urun/{slug}'));
        $routes->add('storefront_customer_account', new Route('/yeni/hesabim'));
        $routes->add('app_home', new Route('/yeni/'));

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
