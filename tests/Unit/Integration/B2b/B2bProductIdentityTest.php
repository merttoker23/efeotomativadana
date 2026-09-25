<?php

namespace App\Tests\Unit\Integration\B2b;

use App\Module\Integration\B2b\B2bProductIdentity;
use App\Module\Integration\B2b\Provider\Efe\EfeFeedNormalizer;
use App\Module\Integration\B2b\Provider\Efe\EfePriceNormalizer;
use App\Module\Pricing\PercentageDiscountCalculator;
use App\Module\Pricing\TaxCalculator;
use PHPUnit\Framework\TestCase;

final class B2bProductIdentityTest extends TestCase
{
    public function testFingerprintUsesOnlyExternalIdAndCanonicalSku(): void
    {
        $first = $this->normalizer()->normalize($this->record());
        $changed = $this->record();
        $changed['oemnumaralari'] = 'MUTABLE-OEM';
        $changed['barkod'] = 'MUTABLE-REFERENCE';
        $changed['ureticiid'] = 'MUTABLE-MANUFACTURER';
        $changedItem = $this->normalizer()->normalize($changed);

        self::assertSame(B2bProductIdentity::fingerprint($first), B2bProductIdentity::fingerprint($changedItem));

        $changedSku = $this->record();
        $changedSku['stokkodu'] = 'ANOTHER-SKU';
        self::assertNotSame(
            B2bProductIdentity::fingerprint($first),
            B2bProductIdentity::fingerprint($this->normalizer()->normalize($changedSku)),
        );
    }

    public function testLegacyFingerprintRetainsThePreFixFormatForCompatibilityFixtures(): void
    {
        $item = $this->normalizer()->normalize($this->record());
        $identifiers = array_map(
            static fn (array $identifier): array => [$identifier[0]->value, $identifier[1]],
            $item->identifiers(),
        );

        self::assertSame(
            hash('sha256', serialize([$item->externalId(), $item->sku(), $identifiers])),
            B2bProductIdentity::legacyFingerprint($item),
        );
    }

    public function testSkuComparisonIsCaseAndWhitespaceInsensitive(): void
    {
        self::assertTrue(B2bProductIdentity::sameSku(' abc-123 ', 'ABC-123'));
        self::assertSame('ABC-123', B2bProductIdentity::canonicalSku(' abc-123 '));
        self::assertFalse(B2bProductIdentity::sameSku('ABC-123', 'ABC-124'));
    }

    /**
     * The Efe feed normalizer and the Product aggregate both trim their SKU, so a padded
     * SKU can never reach the identity comparison from either side. That makes canonical
     * comparison the correct layer for the harmless normalization rules below, and it makes
     * an artificial resolver-level whitespace fixture impossible to construct.
     */
    public function testHarmlessSkuNormalizationsShareOneCanonicalIdentity(): void
    {
        $normalizer = $this->normalizer();
        $canonical = 'ABC-123';
        $variants = ['abc-123', 'ABC-123', ' abc-123 ', 'ABC-123 '];
        $fingerprints = [];
        foreach ($variants as $variant) {
            self::assertSame($canonical, B2bProductIdentity::canonicalSku($variant));
            $record = $this->record();
            $record['stokkodu'] = $variant;
            $fingerprints[$variant] = B2bProductIdentity::fingerprint($normalizer->normalize($record));
        }
        self::assertCount(1, array_unique($fingerprints));

        $padded = $this->record();
        $padded['stokkodu'] = '  abc-123  ';
        self::assertSame('abc-123', $normalizer->normalize($padded)->sku());
    }

    public function testALegacyIdentityVersionIsRecognizedAsPreStableIdentity(): void
    {
        self::assertTrue(B2bProductIdentity::isLegacyIdentity(B2bProductIdentity::LEGACY_FINGERPRINT_VERSION));
        self::assertFalse(B2bProductIdentity::isLegacyIdentity(B2bProductIdentity::FINGERPRINT_VERSION));
        self::assertFalse(B2bProductIdentity::isLegacyIdentity(B2bProductIdentity::FINGERPRINT_VERSION + 1));
    }

    private function normalizer(): EfeFeedNormalizer
    {
        return new EfeFeedNormalizer(
            new EfePriceNormalizer(new TaxCalculator(), new PercentageDiscountCalculator()),
            ['b2b.efeotoyedekparca.com.tr'],
        );
    }

    /** @return array<string, mixed> */
    private function record(): array
    {
        $json = file_get_contents(__DIR__.'/../../../Fixtures/Integration/Efe/efe-feed-sanitized.json');
        self::assertIsString($json);
        $fixture = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertIsArray($fixture['data'][0]);

        return $fixture['data'][0];
    }
}
