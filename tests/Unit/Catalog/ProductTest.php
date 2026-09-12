<?php

namespace App\Tests\Unit\Catalog;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Product;
use App\Module\Catalog\CatalogSource;
use App\Module\Catalog\ProductIdentifierType;
use App\Module\Catalog\PublicationStatus;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    public function testItNormalizesSkuAndKeepsSlugStableWhenRenamed(): void
    {
        $product = new Product(
            sku: '  bal-001  ',
            name: 'Ön Fren Balatası',
            slug: 'on-fren-balatasi',
            source: CatalogSource::Local,
        );

        $product->rename('Seramik Ön Fren Balatası');

        self::assertSame('BAL-001', $product->sku());
        self::assertSame('Seramik Ön Fren Balatası', $product->name());
        self::assertSame('on-fren-balatasi', $product->slug());
        self::assertSame(CatalogSource::Local, $product->source());
    }

    public function testItStoresTypedAutomotiveIdentifiersWithoutChangingSku(): void
    {
        $product = new Product('BAL-001', 'Ön Fren Balatası', 'on-fren-balatasi');

        $product->addIdentifier(ProductIdentifierType::Oem, ' 04e 115 561 h ');
        $product->addIdentifier(ProductIdentifierType::Manufacturer, ' br-100 ');
        $product->addIdentifier(ProductIdentifierType::Reference, ' ref 77 ');

        self::assertSame('BAL-001', $product->sku());
        self::assertSame(
            ['04E 115 561 H', 'BR-100', 'REF 77'],
            array_map(static fn ($identifier): string => $identifier->code(), $product->identifiers()),
        );
    }

    public function testItRejectsTheSameTypedIdentifierTwice(): void
    {
        $product = new Product('BAL-001', 'Ön Fren Balatası', 'on-fren-balatasi');
        $product->addIdentifier(ProductIdentifierType::Oem, '04E-115-561-H');

        $this->expectException(\DomainException::class);

        $product->addIdentifier(ProductIdentifierType::Oem, ' 04e-115-561-h ');
    }

    public function testAttributeValuesCanBeUpdatedWithoutCreatingDuplicateKeys(): void
    {
        $product = new Product('BAL-001', 'Ön Fren Balatası', 'on-fren-balatasi');

        $product->setAttribute('disc-diameter', '280 mm');
        $product->setAttribute('disc-diameter', '290 mm');

        self::assertCount(1, $product->attributes());
        self::assertSame('290 mm', $product->attributes()[0]->value());
    }

    public function testPublicationTransitionsAreExplicit(): void
    {
        $product = new Product(
            'BAL-001',
            'Ön Fren Balatası',
            'on-fren-balatasi',
            brand: new Brand('Bosch', 'bosch'),
        );

        self::assertSame(PublicationStatus::Draft, $product->publicationStatus());

        $product->publish();
        self::assertSame(PublicationStatus::Published, $product->publicationStatus());

        $product->unpublish();
        self::assertSame(PublicationStatus::Draft, $product->publicationStatus());
    }
}
