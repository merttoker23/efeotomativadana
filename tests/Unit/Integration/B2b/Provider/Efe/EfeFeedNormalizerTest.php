<?php

namespace App\Tests\Unit\Integration\B2b\Provider\Efe;

use App\Module\Catalog\ProductIdentifierType;
use App\Module\Integration\B2b\B2bErrorType;
use App\Module\Integration\B2b\B2bProviderStatus;
use App\Module\Integration\B2b\Exception\B2bPermanentProviderException;
use App\Module\Integration\B2b\Provider\Efe\EfeFeedNormalizer;
use App\Module\Integration\B2b\Provider\Efe\EfePriceNormalizer;
use App\Module\Pricing\PercentageDiscountCalculator;
use App\Module\Pricing\TaxCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EfeFeedNormalizerTest extends TestCase
{
    private EfeFeedNormalizer $normalizer;

    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        $this->normalizer = new EfeFeedNormalizer(
            new EfePriceNormalizer(new TaxCalculator(), new PercentageDiscountCalculator()),
            ['b2b.efeotoyedekparca.com.tr'],
        );
        $json = file_get_contents(__DIR__.'/../../../../../Fixtures/Integration/Efe/efe-feed-sanitized.json');
        self::assertIsString($json);
        $fixture = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        $this->fixture = $fixture;
    }

    public function testSanitizedFixtureMatchesTheObservedTwentyNineFieldRecordShape(): void
    {
        $record = $this->record(0);

        self::assertCount(29, $record);
        self::assertSame([
            'id', 'cinsi', 'stokkodu', 'stokturuid', 'marka', 'model', 'ureticiid', 'birim',
            'listefiyati', 'iskonto', 'raf', 'parabirimi', 'oemnumaralari', 'ureticisi', 'urungrubu',
            'barkod', 'kutuiciadet', 'parabirim', 'dovizlifiyat', 'aracmarka', 'desi', 'kdvorani',
            'aciklama', 'modelyili', 'uretici_adi', 'stok_grubu', 'arac_markasi', 'mevcut_stok', 'urunresimleri',
        ], array_keys($record));
    }

    public function testItNormalizesEverySupportedCommerceField(): void
    {
        $item = $this->normalizer->normalize($this->record(0));

        self::assertSame('1001', $item->externalId());
        self::assertSame('GVA 9120688', $item->sku());
        self::assertSame('STOP LAMBASI SOL VW JETTA 201', $item->name());
        self::assertSame('Sanitized fixture description', $item->description());
        self::assertSame('manufacturer:78', $item->brandExternalId());
        self::assertSame('GVA', $item->brandName());
        self::assertNotSame('', $item->categoryExternalId());
        self::assertSame('STOP', $item->categoryName());
        self::assertSame([
            [ProductIdentifierType::Manufacturer, '78'],
            [ProductIdentifierType::Oem, '7711130071'],
            [ProductIdentifierType::Oem, '8200034396'],
            [ProductIdentifierType::Oem, '117209354'],
            [ProductIdentifierType::Reference, 'GVA 9120688'],
        ], $item->identifiers());
        self::assertSame('1', $item->attributes()['efe-box-quantity']);
        self::assertSame('0', $item->attributes()['efe-desi']);
        self::assertSame('Ad', $item->attributes()['efe-unit']);
        self::assertSame('78', $item->attributes()['efe-stock-type-id']);
        self::assertSame(100_664, $item->grossPrice()->minorAmount());
        self::assertSame(2_000, $item->taxRate()->basisPoints());
        self::assertSame(1, $item->stock());
        self::assertSame(['https://b2b.efeotoyedekparca.com.tr/urunler/fixture-1.jpg'], $item->imageUrls());
        self::assertSame([], $item->imageErrors());
    }

    public function testItMapsMissingCategoryToTheConfirmedFallback(): void
    {
        $item = $this->normalizer->normalize($this->record(1));

        self::assertSame('Diğer Ürünler', $item->categoryName());
        self::assertNotSame('', $item->categoryExternalId());
        self::assertNull($item->brandExternalId());
        self::assertNull($item->brandName());
        self::assertNull($item->description());
        self::assertSame(11_000, $item->grossPrice()->minorAmount());
        self::assertSame(0, $item->stock());
    }

    public function testItRecordsInvalidImagesWithoutRejectingTheProduct(): void
    {
        $record = $this->record(0);
        $record['urunresimleri'][] = 'http://b2b.efeotoyedekparca.com.tr/insecure.jpg';

        $item = $this->normalizer->normalize($record);

        self::assertCount(1, $item->imageUrls());
        self::assertCount(1, $item->imageErrors());
        self::assertSame(B2bErrorType::Image, $item->imageErrors()[0]->errorType());
        self::assertSame('1001', $item->imageErrors()[0]->externalId());
    }

    public function testItRejectsImageUrlsContainingCredentials(): void
    {
        $record = $this->record(0);
        $record['urunresimleri'] = [
            'https://user@b2b.efeotoyedekparca.com.tr/user.jpg',
            'https://user:secret@b2b.efeotoyedekparca.com.tr/password.jpg',
        ];

        $item = $this->normalizer->normalize($record);

        self::assertSame([], $item->imageUrls());
        self::assertCount(2, $item->imageErrors());
    }

    public function testItIgnoresUnknownFields(): void
    {
        $record = $this->record(0);
        $record['future_provider_field'] = ['anything'];

        self::assertSame('1001', $this->normalizer->normalize($record)->externalId());
    }

    public function testItRejectsAZeroProviderPrice(): void
    {
        $record = $this->record(0);
        $record['listefiyati'] = '0.00';

        $this->expectException(B2bPermanentProviderException::class);
        $this->normalizer->normalize($record);
    }

    #[DataProvider('invalidRows')]
    public function testItRejectsMalformedRequiredRows(string $field, mixed $value): void
    {
        $record = $this->record(0);
        $record[$field] = $value;

        $this->expectException(B2bPermanentProviderException::class);
        $this->normalizer->normalize($record);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidRows(): iterable
    {
        yield 'blank id' => ['id', ' '];
        yield 'long id' => ['id', str_repeat('x', 192)];
        yield 'blank sku' => ['stokkodu', ''];
        yield 'long sku' => ['stokkodu', str_repeat('S', 65)];
        yield 'blank name' => ['cinsi', ' '];
        yield 'long name' => ['cinsi', str_repeat('N', 256)];
        yield 'currency mismatch' => ['parabirim', 'USD'];
        yield 'negative stock' => ['mevcut_stok', '-1'];
        yield 'fractional stock' => ['mevcut_stok', '1.5'];
        yield 'invalid tax' => ['kdvorani', '101'];
        yield 'oversized OEM code' => ['oemnumaralari', str_repeat('O', 121)];
    }

    public function testProviderStatusExposesOnlySafeEndpointMetadata(): void
    {
        $status = B2bProviderStatus::fromEndpoint(
            'efe',
            'https://b2b.efeotoyedekparca.com.tr/feed.php?token=must-not-leak',
        );

        self::assertSame('efe', $status->providerKey());
        self::assertTrue($status->configured());
        self::assertSame('b2b.efeotoyedekparca.com.tr', $status->endpointHost());

        self::assertFalse(B2bProviderStatus::fromEndpoint('efe', 'http://b2b.efeotoyedekparca.com.tr/feed.php')->configured());
    }

    /** @return array<string, mixed> */
    private function record(int $index): array
    {
        $data = $this->fixture['data'] ?? null;
        self::assertIsArray($data);
        $record = $data[$index] ?? null;
        self::assertIsArray($record);

        return $record;
    }
}
