<?php

namespace App\Tests\Contract\B2b\Efe;

use App\Module\Integration\B2b\B2bSyncMode;
use App\Module\Integration\B2b\Exception\B2bPermanentProviderException;
use App\Module\Integration\B2b\Exception\B2bRetryableProviderException;
use App\Module\Integration\B2b\Provider\Efe\EfeSnapshotDownloader;
use App\Module\Integration\B2b\B2bSnapshotRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EfeSnapshotDownloaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/efe-b2b-downloader-'.bin2hex(random_bytes(5));
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

    public function testItStreamsAValidSnapshotToDiskAndReturnsVerifiedMetadata(): void
    {
        $json = $this->fixtureJson();
        $client = new MockHttpClient(new MockResponse($json, [
            'response_headers' => ['content-type: application/json; charset=utf-8'],
        ]));
        $downloader = $this->downloader($client);

        $snapshot = $downloader->download($this->request());

        self::assertFileExists($snapshot->path);
        self::assertFileDoesNotExist($snapshot->path.'.part');
        self::assertSame(strlen($json), $snapshot->byteCount);
        self::assertSame(hash('sha256', $json), $snapshot->sha256);
        self::assertSame(2, $snapshot->declaredCount);
        self::assertFalse($snapshot->reused);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testItReusesAnExistingValidSnapshotWithoutAnotherProviderRequest(): void
    {
        $json = $this->fixtureJson();
        $client = new MockHttpClient(new MockResponse($json, [
            'response_headers' => ['content-type: application/json'],
        ]));
        $downloader = $this->downloader($client);
        $first = $downloader->download($this->request());

        $reused = $downloader->download($this->request($first->path, $first->sha256));

        self::assertTrue($reused->reused);
        self::assertSame($first->path, $reused->path);
        self::assertSame($first->sha256, $reused->sha256);
        self::assertSame(1, $client->getRequestsCount());
    }

    #[DataProvider('invalidResponses')]
    public function testItRejectsInvalidHttpResponses(int $status, string $contentType, int $maxBytes, string $expectedException): void
    {
        $client = new MockHttpClient(new MockResponse('{}', [
            'http_code' => $status,
            'response_headers' => ['content-type: '.$contentType],
        ]));
        $downloader = $this->downloader($client, $maxBytes);

        $this->expectException($expectedException);
        $downloader->download($this->request());
        self::assertFileDoesNotExist($this->directory.'/42.json.part');
    }

    /** @return iterable<string, array{int, string, int, class-string<\Throwable>}> */
    public static function invalidResponses(): iterable
    {
        yield 'request timeout' => [408, 'application/json', 1_000_000, B2bRetryableProviderException::class];
        yield 'server error' => [503, 'application/json', 1_000_000, B2bRetryableProviderException::class];
        yield 'not json' => [200, 'text/html', 1_000_000, B2bPermanentProviderException::class];
        yield 'too large' => [200, 'application/json', 1, B2bPermanentProviderException::class];
    }

    public function testItRemovesDownloadedFileWhenMetadataDoesNotMatchTheContract(): void
    {
        $downloader = $this->downloader(new MockHttpClient(new MockResponse(
            '{"ok":false,"count":1,"data":[]}',
            ['response_headers' => ['content-type: application/json']],
        )));

        try {
            $downloader->download($this->request());
            self::fail('Invalid metadata must abort the snapshot.');
        } catch (B2bPermanentProviderException) {
            self::assertFileDoesNotExist($this->directory.'/42.json');
            self::assertFileDoesNotExist($this->directory.'/42.json.part');
        }
    }

    public function testItRejectsAValidButIncompleteDataArray(): void
    {
        $downloader = $this->downloader(new MockHttpClient(new MockResponse(
            '{"ok":true,"count":3,"data":[]}',
            ['response_headers' => ['content-type: application/json']],
        )));

        $this->expectException(B2bPermanentProviderException::class);
        $downloader->download($this->request());
    }

    public function testItRefusesToReplaceAMissingPersistedSnapshot(): void
    {
        $client = new MockHttpClient(new MockResponse('{}'));
        $downloader = $this->downloader($client);

        try {
            $downloader->download($this->request($this->directory.'/missing.json'));
            self::fail('A resumed run must not silently download a replacement snapshot.');
        } catch (B2bPermanentProviderException) {
            self::assertSame(0, $client->getRequestsCount());
        }
    }

    public function testItRefusesAChangedPersistedSnapshot(): void
    {
        $path = $this->directory.'/existing.json';
        file_put_contents($path, $this->fixtureJson());
        $client = new MockHttpClient(new MockResponse('{}'));
        $downloader = $this->downloader($client);

        $this->expectException(B2bPermanentProviderException::class);
        try {
            $downloader->download(new B2bSnapshotRequest('efe', 42, B2bSyncMode::Full, $this->directory, $path, str_repeat('0', 64)));
        } finally {
            self::assertSame(0, $client->getRequestsCount());
        }
    }

    public function testItRejectsRedirectBeforeContactingADisallowedHost(): void
    {
        $client = new MockHttpClient(new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['location: https://evil.example/feed.json'],
        ]));
        $downloader = $this->downloader($client);

        try {
            $downloader->download($this->request());
            self::fail('A redirect to a disallowed host must be rejected.');
        } catch (B2bPermanentProviderException) {
            self::assertSame(1, $client->getRequestsCount());
        }
    }

    public function testItClassifiesTransportFailureAsRetryableAndRemovesPartialFile(): void
    {
        $body = (static function (): iterable {
            yield '{"ok":true,"count":1,"data":[';
            throw new TransportException('Connection lost.');
        })();
        $downloader = $this->downloader(new MockHttpClient(new MockResponse($body, [
            'response_headers' => ['content-type: application/json'],
        ])));

        try {
            $downloader->download($this->request());
            self::fail('A transport failure must abort the snapshot.');
        } catch (B2bRetryableProviderException) {
            self::assertFileDoesNotExist($this->directory.'/42.json.part');
        }
    }

    public function testItRejectsAnEndpointOutsideTheAllowedHosts(): void
    {
        $downloader = new EfeSnapshotDownloader(
            httpClient: new MockHttpClient(new MockResponse('{}', ['response_headers' => ['content-type: application/json']])),
            endpointUrl: 'https://evil.example/feed.json',
            allowedHosts: ['b2b.efeotoyedekparca.com.tr'],
            snapshotDirectory: $this->directory,
            maxBytes: 1_000_000,
            maxConnectDuration: 20,
            readTimeout: 120,
            overallTimeout: 600,
            maxRedirects: 3,
            caBundle: null,
        );

        $this->expectException(B2bPermanentProviderException::class);
        $downloader->download($this->request());
    }

    private function downloader(MockHttpClient $client, int $maxBytes = 1_000_000): EfeSnapshotDownloader
    {
        return new EfeSnapshotDownloader(
            httpClient: $client,
            endpointUrl: 'https://b2b.efeotoyedekparca.com.tr/feed.json',
            allowedHosts: ['b2b.efeotoyedekparca.com.tr'],
            snapshotDirectory: $this->directory,
            maxBytes: $maxBytes,
            maxConnectDuration: 20,
            readTimeout: 120,
            overallTimeout: 600,
            maxRedirects: 3,
            caBundle: null,
        );
    }

    private function request(?string $existingPath = null, ?string $existingHash = null): B2bSnapshotRequest
    {
        return new B2bSnapshotRequest('efe', 42, B2bSyncMode::Full, $this->directory, $existingPath, $existingHash);
    }

    private function fixtureJson(): string
    {
        $path = __DIR__.'/../../../Fixtures/Integration/Efe/efe-feed-sanitized.json';
        $json = file_get_contents($path);
        self::assertIsString($json);

        return $json;
    }
}
