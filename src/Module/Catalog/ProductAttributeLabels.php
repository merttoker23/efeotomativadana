<?php

declare(strict_types=1);

namespace App\Module\Catalog;

/**
 * The name a product attribute is given to a customer.
 *
 * An attribute is stored as a pair of opaque strings, and the key of one is an integration name:
 * `efe-box-quantity`, `efe-stock-type-id`, `efe-desi`. Turning such a key into a heading by
 * mechanical title-casing is what put "Efe Box Quantity" and "Efe Stock Type Id" in front of
 * customers on the product page — the source system's name, its prefix and its field type, none
 * of which mean anything to the person reading a parts catalogue.
 *
 * This class is the one place that turns a key into a heading. It changes the label only: the key
 * itself, the value beside it, the column it occupies, and the B2B synchronisation that writes it
 * are all untouched, so an administrator and the integration keep seeing exactly what they always
 * saw. Only the storefront's display layer asks this class.
 *
 * A key with a known customer-facing name is translated here. Any other key falls back to a
 * derived heading with the integration prefix and the identifier noise removed, so a field the
 * integration adds tomorrow is presented as words rather than as an internal name — and a store
 * that never integrates anything still gets "Disk Cap" rather than a raw key.
 */
final class ProductAttributeLabels
{
    /**
     * The customer-facing name of every attribute key the Efe feed writes.
     *
     * Written out in full rather than derived: "Adet", "Desi" and "Stok Tipi" are not a mechanical
     * rewriting of their keys, and a Turkish shop that knows what a desi is should not have the
     * catalogue spell it "Desi" for them.
     */
    private const array LABELS = [
        'efe-box-quantity' => 'Adet',
        'efe-desi' => 'Desi',
        'efe-stock-type-id' => 'Stok Tipi',
        'efe-unit' => 'Birim',
        'efe-vehicle-brand' => 'Araç Markası',
        'efe-model-year' => 'Model Yılı',
        'efe-brand-code' => 'Marka Kodu',
        'efe-model-code' => 'Model Kodu',
        'efe-vehicle-brand-code' => 'Araç Marka Kodu',
    ];

    /** The integration prefix, which names the source system rather than the part. */
    private const string SOURCE_PREFIX = 'efe';

    /**
     * Words that name a field's type rather than its content.
     *
     * "Id" is the only one: it is how a database column says "this is the identifier", which is
     * precisely the thing a customer must not be shown. "Stok Tipi" keeps its "Tipi", because
     * there that word is the subject of the row and not a column type.
     */
    private const array IDENTIFIER_NOISE = ['id', 'ids'];

    public static function label(string $key): string
    {
        $key = mb_strtolower(trim($key));
        if ('' === $key) {
            return '';
        }

        return self::LABELS[$key] ?? self::derive($key);
    }

    /**
     * A heading for a key this class has no translation for: the source prefix removed, the
     * separators read as spaces, the identifier words dropped, and what is left title-cased.
     *
     * A key that is nothing but a prefix and identifier words — `efe-id`, say — has no words
     * left to title-case, and returns empty rather than an empty-looking heading.
     */
    private static function derive(string $key): string
    {
        $withoutPrefix = preg_replace(
            '/^'.preg_quote(self::SOURCE_PREFIX, '/').'[-_]/',
            '',
            $key,
        ) ?? $key;

        $words = preg_split('/[-_\s]+/', $withoutPrefix) ?: [];
        $words = array_values(array_filter(
            $words,
            static fn (string $word): bool => '' !== $word && !\in_array($word, self::IDENTIFIER_NOISE, true),
        ));

        return implode(' ', array_map(
            static fn (string $word): string => mb_convert_case($word, \MB_CASE_TITLE, 'UTF-8'),
            $words,
        ));
    }
}