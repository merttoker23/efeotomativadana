<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Module\Catalog\ProductAttributeLabels;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The labels a product's technical specification is printed under.
 *
 * The keys under test are the ones the Efe integration writes. What matters is not that each of
 * them reads well — that is a shop's decision — but that the integration's own name, its prefix
 * and its field types never reach a customer.
 */
final class ProductAttributeLabelsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function knownKeys(): iterable
    {
        yield 'box quantity' => ['efe-box-quantity', 'Adet'];
        yield 'desi' => ['efe-desi', 'Desi'];
        yield 'stock type' => ['efe-stock-type-id', 'Stok Tipi'];
        yield 'unit' => ['efe-unit', 'Birim'];
        yield 'vehicle brand' => ['efe-vehicle-brand', 'Araç Markası'];
        yield 'model year' => ['efe-model-year', 'Model Yılı'];
        yield 'brand code' => ['efe-brand-code', 'Marka Kodu'];
        yield 'model code' => ['efe-model-code', 'Model Kodu'];
        yield 'vehicle brand code' => ['efe-vehicle-brand-code', 'Araç Marka Kodu'];
    }

    #[DataProvider('knownKeys')]
    public function testEveryIntegrationKeyIsGivenACustomerFacingName(string $key, string $expected): void
    {
        self::assertSame($expected, ProductAttributeLabels::label($key));
    }

    /**
     * The whole point of the class: no heading a customer can read may name the integration, its
     * prefix, or the fact that a field is an identifier rather than a value.
     */
    #[DataProvider('knownKeys')]
    public function testNoIntegrationNameOrFieldTypeReachesACustomer(string $key, string $expected): void
    {
        $label = ProductAttributeLabels::label($key);

        self::assertSame($expected, $label);
        self::assertStringNotContainsStringIgnoringCase('efe', $label);
        self::assertStringNotContainsStringIgnoringCase('id', $label);
        self::assertStringNotContainsString('-', $label);
    }

    public function testAKnownKeyIsMatchedHoweverTheFeedSpelledIt(): void
    {
        self::assertSame('Adet', ProductAttributeLabels::label('EFE-Box-Quantity'));
        self::assertSame('Adet', ProductAttributeLabels::label('  efe-box-quantity  '));
    }

    /**
     * An unknown key is not translated — nothing here knows Turkish — but it is reduced to its own
     * words: the source system's prefix goes, and what is left is set as words rather than left as a
     * key. A feed that grows a field tomorrow is presented as a heading, not as an internal name.
     */
    public function testAnUnknownIntegrationKeyIsReducedToItsOwnWords(): void
    {
        self::assertSame('Side Quantity', ProductAttributeLabels::label('efe-side-quantity'));
        self::assertSame('Color Code', ProductAttributeLabels::label('efe-color-code'));
    }

    /** A key with no prefix is untouched by the prefix rule and keeps its own title casing. */
    public function testAKeyThisStoreOwnedKeepsItsOwnWords(): void
    {
        self::assertSame('Disk Cap', ProductAttributeLabels::label('disk-cap'));
        self::assertSame('Aks Disi', ProductAttributeLabels::label('aks_disi'));
    }

    /**
     * A local key of the shop's own has no integration prefix to strip, and the identifier rule is
     * the only thing that changes it: "Id" is how a column says what it is, not what it holds.
     */
    public function testAnIdentifierWordIsNotPartOfALabel(): void
    {
        self::assertSame('Stock', ProductAttributeLabels::label('stock-id'));
        self::assertSame('', ProductAttributeLabels::label('efe-id'));
    }

    public function testAnEmptyKeyHasNoLabel(): void
    {
        self::assertSame('', ProductAttributeLabels::label('   '));
    }
}
