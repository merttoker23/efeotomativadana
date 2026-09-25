<?php

namespace App\Module\Integration\B2b;

final class B2bProductIdentity
{
    public const FINGERPRINT_VERSION = 2;
    public const LEGACY_FINGERPRINT_VERSION = 1;

    public static function fingerprint(NormalizedCatalogFeedItem $item): string
    {
        return hash('sha256', serialize([
            'b2b-product-identity-v'.self::FINGERPRINT_VERSION,
            $item->externalId(),
            self::normalizeSku($item->sku()),
        ]));
    }

    /**
     * Pre-v2 fingerprint: provider external ID, the raw provider SKU and the mutable
     * catalog identifiers. It is kept so legacy mappings can still be recognized and
     * rebuilt; it must never take part in stable product identity because the raw SKU
     * and the identifiers both change harmlessly over time.
     */
    public static function legacyFingerprint(NormalizedCatalogFeedItem $item): string
    {
        $identifiers = array_map(
            static fn (array $identifier): array => [$identifier[0]->value, $identifier[1]],
            $item->identifiers(),
        );

        return hash('sha256', serialize([
            $item->externalId(),
            $item->sku(),
            $identifiers,
        ]));
    }

    /**
     * A mapping written before the stable v2 identity was introduced. Such a mapping is
     * still bound to its product by the external mapping key, so it only needs canonical
     * SKU consistency to transition safely onto the stable identity.
     */
    public static function isLegacyIdentity(int $identityVersion): bool
    {
        return $identityVersion < self::FINGERPRINT_VERSION;
    }

    public static function sameSku(string $first, string $second): bool
    {
        return self::normalizeSku($first) === self::normalizeSku($second);
    }

    public static function canonicalSku(string $sku): string
    {
        return self::normalizeSku($sku);
    }

    private static function normalizeSku(string $sku): string
    {
        return mb_strtoupper(trim($sku));
    }
}
