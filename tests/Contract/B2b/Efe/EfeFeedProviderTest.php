<?php

namespace App\Tests\Contract\B2b\Efe;

use App\Module\Integration\B2b\B2bErrorType;
use App\Module\Integration\B2b\B2bSnapshotRequest;
use App\Module\Integration\B2b\B2bSyncCheckpoint;
use App\Module\Integration\B2b\B2bSyncMode;
use App\Module\Integration\B2b\Exception\B2bPermanentProviderException;
use App\Module\Integration\B2b\Provider\Efe\EfeFeedNormalizer;
use App\Module\Integration\B2b\Provider\Efe\EfeFeedProvider;
use App\Module\Integration\B2b\Provider\Efe\EfePriceNormalizer;
use App\Module\Integration\B2b\Provider\Efe\EfeSnapshotDownloader;
use App\Module\Pricing\PercentageDiscountCalculator;
use App\Module\Pricing\TaxCalculator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EfeFeedProviderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/efe-b2b-provider-'.bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        foreach (new \FilesystemIterator($this->directory) as $file) {
            if ($file->isFile()) {
                @unlink($file->getPathname());
            }
        }
        @rmdir($this->directory);
    }

    public function testItStreamsNormalizedRecordsOneAtATime(): void
    {
        $provider = $this->provider($this->fixture());
        $snapshot = $provider->prepareSnapshot($this->request());

        $records = iterator_to_array($provider->streamItems($snapshot, new B2bSyncCheckpoint()), false);

        self::assertCount(2, $records);
        self::assertTrue($records[0]->isSuccess());
        self::assertSame('1001', $records[0]->item()?->externalId());
        self::assertSame('1002', $records[1]->item()?->externalId());
    }

    public function testCheckpointSkipsExactlyTheAlreadyProcessedPrefix(): void
    {
        $provider = $this->provider($this->fixture());
        $snapshot = $provider->prepareSnapshot($this->request());

        $records = iterator_to_array($provider->streamItems($snapshot, new B2bSyncCheckpoint(1)), false);

        self::assertCount(1, $records);
        self::assertSame('1002', $records[0]->item()?->externalId());
    }

    public function testInvalidStockIsClassifiedAsAStockError(): void
    {
        $records = json_decode($this->fixture(), true, 512, JSON_THROW_ON_ERROR);
        $records['data'][0]['mevcut_stok'] = '-1';
        $provider = $this->provider(json_encode($records, JSON_THROW_ON_ERROR));
        $snapshot = $provider->prepareSnapshot($this->request());

        $streamed = iterator_to_array($provider->streamItems($snapshot, new B2bSyncCheckpoint()), false);

        self::assertFalse($streamed[0]->isSuccess());
        self::assertSame(B2bErrorType::InvalidStock, $streamed[0]->error()->errorType());
    }
    public function testMalformedRowYieldsAnErrorAndTheFollowingRowStillSucceeds(): void
    {
        $fixture = json_decode($this->fixture(), true, 512, JSON_THROW_ON_ERROR);
        $fixture['data'][0]['listefiyati'] = '-1.00';
        $provider = $this->provider(json_encode($fixture, JSON_THROW_ON_ERROR));
        $snapshot = $provider->prepareSnapshot($this->request());

        $records = iterator_to_array($provider->streamItems($snapshot, new B2bSyncCheckpoint()), false);

        self::assertFalse($records[0]->isSuccess());
        self::assertSame(B2bErrorType::InvalidPrice, $records[0]->error()->errorType());
        self::assertSame('1001', $records[0]->error()->externalId());
        self::assertTrue($records[1]->isSuccess());
        self::assertSame('1002', $records[1]->item()?->externalId());
    }

    public function testZeroPriceRowIsRejectedAsAnInvalidPriceWithoutStoppingTheStream(): void
    {
        $fixture = json_decode($this->fixture(), true, 512, JSON_THROW_ON_ERROR);
        $fixture['data'][0]['listefiyati'] = '0.00';
        $provider = $this->provider(json_encode($fixture, JSON_THROW_ON_ERROR));
        $snapshot = $provider->prepareSnapshot($this->request());

        $records = iterator_to_array($provider->streamItems($snapshot, new B2bSyncCheckpoint()), false);

        self::assertFalse($records[0]->isSuccess());
        self::assertSame(B2bErrorType::InvalidPrice, $records[0]->error()->errorType());
        self::assertSame('1001', $records[0]->error()->externalId());
        self::assertTrue($records[1]->isSuccess());
        self::assertSame('1002', $records[1]->item()?->externalId());
    }

    public function testMalformedTopLevelJsonRaisesPermanentContractFailure(): void
    {
        $provider = $this->provider('{"ok":true,"count":2,"data":[');

        $this->expectException(B2bPermanentProviderException::class);
        $provider->prepareSnapshot($this->request());
    }

    private function provider(string $json): EfeFeedProvider
    {
        $downloader = new EfeSnapshotDownloader(
            httpClient: new MockHttpClient(new MockResponse($json, ['response_headers' => ['content-type: application/json']])),
            endpointUrl: 'https://b2b.efeotoyedekparca.com.tr/feed.json',
            allowedHosts: ['b2b.efeotoyedekparca.com.tr'],
            snapshotDirectory: $this->directory,
            maxBytes: 1_000_000,
            maxConnectDuration: 20,
            readTimeout: 120,
            overallTimeout: 600,
            maxRedirects: 3,
            caBundle: null,
        );
        $normalizer = new EfeFeedNormalizer(
            new EfePriceNormalizer(new TaxCalculator(), new PercentageDiscountCalculator()),
            ['b2b.efeotoyedekparca.com.tr'],
        );

        return new EfeFeedProvider($downloader, $normalizer, 'https://b2b.efeotoyedekparca.com.tr/feed.json');
    }

    private function request(): B2bSnapshotRequest
    {
        return new B2bSnapshotRequest('efe', 42, B2bSyncMode::Full, $this->directory);
    }

    private function fixture(): string
    {
        $json = file_get_contents(__DIR__.'/../../../Fixtures/Integration/Efe/efe-feed-sanitized.json');
        self::assertIsString($json);

        return $json;
    }
}
