<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use App\Module\Catalog\Query\CatalogOption;
use App\Module\Catalog\Query\CatalogProductDetail;
use App\Module\Seo\StructuredData\ProductData;
use App\Module\Seo\SeoText;
use App\Module\Seo\SeoUrlFactory;
use App\Shared\Money\Money;
use App\Shared\PublicUrlGenerator;
use App\Twig\StorefrontMediaExtension;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\PathPackage;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * A price, a currency and an availability published as structured data is a claim a search
 * engine can show to a shopper. If it disagrees with what the customer actually pays, the
 * store is advertising something it does not sell — so these tests are about agreement with
 * the local records, not about the shape of the JSON.
 */
final class ProductDataTest extends TestCase
{
    public function testThePriceCurrencyAndAvailabilityComeFromTheTrustedLocalRecords(): void
    {
        $graph = $this->data()->for(
            $this->product(sellPrice: Money::ofMinor(12_500, 'TRY'), quantity: 4, sellable: true),
            'https://magaza.example/urun/yag-filtresi',
        );

        self::assertSame('Product', $graph['@type']);
        self::assertSame('Yag Filtresi', $graph['name']);
        self::assertSame('FILTER-001', $graph['sku']);
        self::assertSame('https://magaza.example/urun/yag-filtresi', $graph['url']);
        self::assertSame('125.00', $graph['offers']['price']);
        self::assertSame('TRY', $graph['offers']['priceCurrency']);
        self::assertSame('https://schema.org/InStock', $graph['offers']['availability']);
    }

    public function testAnOutOfStockProductSaysSoRatherThanClaimingItCanBeBought(): void
    {
        $graph = $this->data()->for(
            $this->product(sellPrice: Money::ofMinor(12_500, 'TRY'), quantity: 0, sellable: false),
            'https://magaza.example/urun/yag-filtresi',
        );

        self::assertSame('https://schema.org/OutOfStock', $graph['offers']['availability']);
    }

    public function testStockThatExistsButIsNotOfferedIsNotTheSameAsNoStock(): void
    {
        $graph = $this->data()->for(
            $this->product(sellPrice: Money::ofMinor(12_500, 'TRY'), quantity: 7, sellable: false),
            'https://magaza.example/urun/yag-filtresi',
        );

        self::assertSame('https://schema.org/BackOrder', $graph['offers']['availability']);
    }

    public function testAPricelessProductCarriesNoOfferAtAllRatherThanAZeroPrice(): void
    {
        $graph = $this->data()->for(
            $this->product(sellPrice: null, quantity: 4, sellable: true),
            'https://magaza.example/urun/yag-filtresi',
        );

        // "0.00" is a claim that the part is free, and a shopper who believes it is a customer
        // service problem. No offer is the honest statement.
        self::assertArrayNotHasKey('offers', $graph);
    }

    public function testTheOfferPointsAtTheProductsOwnCanonicalUrl(): void
    {
        $canonical = 'https://magaza.example/urun/yag-filtresi';

        $graph = $this->data()->for($this->product(sellPrice: Money::ofMinor(1_000, 'TRY')), $canonical);

        self::assertSame($canonical, $graph['offers']['url']);
    }

    public function testTheBrandIsNamedAndOmittedEntirelyWhenThereIsNone(): void
    {
        $withBrand = $this->data()->for($this->product(sellPrice: Money::ofMinor(1_000, 'TRY'), brandName: 'Bosch'), 'https://x/y');
        self::assertSame(['@type' => 'Brand', 'name' => 'Bosch'], $withBrand['brand']);

        $without = $this->data()->for($this->product(sellPrice: Money::ofMinor(1_000, 'TRY')), 'https://x/y');
        self::assertArrayNotHasKey('brand', $without);
    }

    public function testEveryStoredImageIsAdvertisedAndNoneIsInventedWhenThereAreNone(): void
    {
        $withImages = $this->data()->for(
            $this->product(sellPrice: Money::ofMinor(1_000, 'TRY'), images: [
                ['path' => '/uploads/products/a.jpg', 'alt' => 'A'],
                ['path' => '/uploads/products/b.jpg', 'alt' => 'B'],
            ]),
            'https://x/y',
        );
        self::assertCount(2, $withImages['image']);

        $without = $this->data()->for($this->product(sellPrice: Money::ofMinor(1_000, 'TRY')), 'https://x/y');
        self::assertArrayNotHasKey('image', $without);
    }

    public function testTheDescriptionIsPlainTextAndNeverMarkup(): void
    {
        $graph = $this->data()->for(
            $this->product(sellPrice: Money::ofMinor(1_000, 'TRY'), description: '<p>Yag <b>filtresi</b></p>'),
            'https://x/y',
        );

        self::assertSame('Yag filtresi', $graph['description']);
        self::assertStringNotContainsString('<', $graph['description']);
    }

    public function testTheAutomotiveCodesAreOfferedUnderTheirOwnIdentifier(): void
    {
        $graph = $this->data()->for(
            $this->product(
                sellPrice: Money::ofMinor(1_000, 'TRY'),
                identifiers: [['type' => 'manufacturer', 'label' => 'Üretici Kodu', 'code' => 'MANN-W712']],
            ),
            'https://x/y',
        );

        self::assertSame('MANN-W712', $graph['mpn']);
    }

    private function data(): ProductData
    {
        return new ProductData($this->urls(), new \App\Module\Seo\SeoText());
    }

    private function urls(): SeoUrlFactory
    {
        $routes = new RouteCollection();
        $routes->add('storefront_catalog_product', new Route('/urun/{slug}'));

        return new SeoUrlFactory(
            new PublicUrlGenerator(
                new Router(new ClosureLoader(), static fn (): RouteCollection => $routes, ['cache_dir' => null], new RequestContext()),
                'https://magaza.example',
            ),
            new StorefrontMediaExtension(
                new Packages(new PathPackage('/', new EmptyVersionStrategy())),
                '/',
            ),
        );
    }

    /**
     * @param list<array{path: string, alt: string}>                       $images
     * @param list<array{type: string, label: string, code: string}>       $identifiers
     */
    private function product(
        ?Money $sellPrice,
        int $quantity = 1,
        bool $sellable = true,
        ?string $description = null,
        array $images = [],
        ?string $brandName = null,
        array $identifiers = [],
    ): CatalogProductDetail {
        return new CatalogProductDetail(
            id: 1,
            sku: 'FILTER-001',
            name: 'Yag Filtresi',
            slug: 'yag-filtresi',
            description: $description,
            brandName: $brandName,
            brandSlug: null === $brandName ? null : 'bosch',
            images: $images,
            identifiers: $identifiers,
            attributes: [],
            categories: [new CatalogOption('Frenler', 'frenler', 1)],
            basePrice: $sellPrice,
            sellPrice: $sellPrice,
            onSale: false,
            quantity: $quantity,
            sellable: $sellable,
        );
    }
}
