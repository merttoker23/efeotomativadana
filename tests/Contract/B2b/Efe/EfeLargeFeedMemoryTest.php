<?php

namespace App\Tests\Contract\B2b\Efe;

use App\Module\Integration\B2b\B2bSnapshot;
use App\Module\Integration\B2b\B2bSyncCheckpoint;
use App\Module\Integration\B2b\Provider\Efe\EfeFeedNormalizer;
use App\Module\Integration\B2b\Provider\Efe\EfeFeedProvider;
use App\Module\Integration\B2b\Provider\Efe\EfePriceNormalizer;
use App\Module\Integration\B2b\Provider\Efe\EfeSnapshotDownloader;
use App\Module\Pricing\PercentageDiscountCalculator;
use App\Module\Pricing\TaxCalculator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class EfeLargeFeedMemoryTest extends TestCase
{
    public function testNinetyThousandRecordsAreParsedWithBoundedMemory(): void
    {
        $directory = sys_get_temp_dir().'/efe-b2b-large-'.bin2hex(random_bytes(5));
        mkdir($directory, 0755, true);
        $path = $directory.'/large.json';
        $originalMemoryLimit = (string) ini_get('memory_limit');
        try {
            $this->writeLargeFeed($path, 90_001);
            $downloader = new EfeSnapshotDownloader(
                httpClient: new MockHttpClient(),
                endpointUrl: 'https://b2b.efeotoyedekparca.com.tr/feed.json',
                allowedHosts: ['b2b.efeotoyedekparca.com.tr'],
                snapshotDirectory: $directory,
                maxBytes: 268_435_456,
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
            $provider = new EfeFeedProvider($downloader, $normalizer, 'https://b2b.efeotoyedekparca.com.tr/feed.json');
            $snapshot = new B2bSnapshot($path, (int) filesize($path), 90_001, hash_file('sha256', $path));
            ini_set('memory_limit', '128M');
            gc_collect_cycles();
            $baseline = memory_get_usage(true);
            $count = 0;
            $first = null;
            $last = null;
            foreach ($provider->streamItems($snapshot, new B2bSyncCheckpoint()) as $record) {
                self::assertTrue($record->isSuccess());
                $first ??= $record->item()?->externalId();
                $last = $record->item()?->externalId();
                ++$count;
            }
            $memoryDelta = max(0, memory_get_usage(true) - $baseline);

            self::assertSame(90_001, $count);
            self::assertSame('p12-1', $first);
            self::assertSame('p12-90001', $last);
            self::assertLessThan(16 * 1024 * 1024, $memoryDelta);
        } finally {
            ini_set('memory_limit', '' === $originalMemoryLimit ? '-1' : $originalMemoryLimit);
            @unlink($path);
            @rmdir($directory);
        }
    }

    private function writeLargeFeed(string $path, int $count): void
    {
        $stream = fopen($path, 'wb');
        self::assertIsResource($stream);
        fwrite($stream, '{"ok":true,"count":'.$count.',"data":[');
        for ($index = 1; $index <= $count; ++$index) {
            if ($index > 1) {
                fwrite($stream, ',');
            }
            $record = [
                'id' => 'p12-'.$index,
                'cinsi' => 'Synthetic large-feed product '.$index,
                'stokkodu' => 'P12-'.$index,
                'stokturuid' => '0',
                'marka' => '0',
                'model' => '0',
                'ureticiid' => null,
                'birim' => null,
                'listefiyati' => '100.00',
                'iskonto' => '0',
                'raf' => null,
                'parabirimi' => 'TL',
                'oemnumaralari' => null,
                'ureticisi' => null,
                'urungrubu' => null,
                'barkod' => 'P12-'.$index,
                'kutuiciadet' => '1',
                'parabirim' => 'TL',
                'dovizlifiyat' => null,
                'aracmarka' => '0',
                'desi' => '0',
                'kdvorani' => '20',
                'aciklama' => '',
                'modelyili' => null,
                'uretici_adi' => null,
                'stok_grubu' => 'LARGE',
                'arac_markasi' => null,
                'mevcut_stok' => (string) ($index % 5),
                'urunresimleri' => [],
            ];
            fwrite($stream, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        fwrite($stream, ']}');
        fclose($stream);
    }
}
