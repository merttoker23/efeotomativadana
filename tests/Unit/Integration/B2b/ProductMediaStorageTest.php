<?php

namespace App\Tests\Unit\Integration\B2b;

use App\Module\Integration\B2b\Exception\B2bPermanentProviderException;
use App\Module\Integration\B2b\Exception\B2bRetryableProviderException;
use App\Module\Integration\B2b\ProductMediaStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProductMediaStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/efe-product-media-'.bin2hex(random_bytes(5));
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

    public function testItStoresARealImageUnderARandomLocalProductPath(): void
    {
        $storage = $this->storage(new MockHttpClient(new MockResponse($this->pngBytes(), [
            'response_headers' => ['content-type: text/plain'],
        ])));

        $stored = $storage->store('https://b2b.efeotoyedekparca.com.tr/urunler/1001.png', 'Product 1001');

        self::assertMatchesRegularExpression('~^/uploads/products/[a-f0-9]{32}\.png$~', $stored->path);
        self::assertFileExists($stored->absolutePath);
        self::assertSame($this->directory, dirname($stored->absolutePath));
        self::assertSame([], glob($this->directory.'/*.part') ?: []);

        $storage->remove($stored);
        self::assertFileDoesNotExist($stored->absolutePath);
    }

    /**
     * The synchronization worker runs from cron, which commonly starts with a restrictive umask
     * such as 0077. The stored file would then be created 0600, unreadable by the web server
     * process, and the storefront image would answer 403 even though the row and the file exist.
     * The storage layer must publish the file mode itself instead of inheriting the umask.
     */
    public function testItStoresAWorldReadableFileEvenUnderARestrictiveUmask(): void
    {
        $storage = $this->storage(new MockHttpClient(new MockResponse($this->pngBytes(), [
            'response_headers' => ['content-type: text/plain'],
        ])));
        $previousUmask = umask(0077);

        try {
            $stored = $storage->store('https://b2b.efeotoyedekparca.com.tr/urunler/1001.png', 'Product 1001');
            $mode = fileperms($stored->absolutePath);
            self::assertIsInt($mode);
            self::assertSame(
                0o044,
                $mode & 0o044,
                'A stored product image must stay readable by the web server process.',
            );
        } finally {
            umask($previousUmask);
        }
    }

    #[DataProvider('invalidResponses')]
    public function testItRejectsInvalidSourcesAndBodies(MockResponse $response, string $sourceUrl, string $expectedException): void
    {
        $storage = $this->storage(new MockHttpClient($response));

        $caught = null;
        try {
            $storage->store($sourceUrl, 'Product');
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf($expectedException, $caught);
        self::assertSame([], glob($this->directory.'/*') ?: []);
    }

    /** @return iterable<string, array{MockResponse, string, class-string<\Throwable>}> */
    public static function invalidResponses(): iterable
    {
        yield 'insecure URL' => [
            new MockResponse('body', ['response_headers' => ['content-type: image/png']]),
            'http://b2b.efeotoyedekparca.com.tr/insecure.png',
            B2bPermanentProviderException::class,
        ];
        yield 'wrong host' => [
            new MockResponse('body', ['response_headers' => ['content-type: image/png']]),
            'https://evil.example/image.png',
            B2bPermanentProviderException::class,
        ];
        yield 'server failure' => [
            new MockResponse('body', ['http_code' => 503, 'response_headers' => ['content-type: image/png']]),
            'https://b2b.efeotoyedekparca.com.tr/server.png',
            B2bRetryableProviderException::class,
        ];
        yield 'request timeout' => [
            new MockResponse('body', ['http_code' => 408, 'response_headers' => ['content-type: image/png']]),
            'https://b2b.efeotoyedekparca.com.tr/timeout.png',
            B2bRetryableProviderException::class,
        ];
        yield 'not an image' => [
            new MockResponse('<?php echo "bad";', ['response_headers' => ['content-type: image/png']]),
            'https://b2b.efeotoyedekparca.com.tr/fake.png',
            B2bPermanentProviderException::class,
        ];
        yield 'empty body' => [
            new MockResponse('', ['response_headers' => ['content-type: image/png']]),
            'https://b2b.efeotoyedekparca.com.tr/empty.png',
            B2bPermanentProviderException::class,
        ];
        yield 'dimensions too large' => [
            new MockResponse(self::oversizedPngHeader(), ['response_headers' => ['content-type: image/png']]),
            'https://b2b.efeotoyedekparca.com.tr/large.png',
            B2bPermanentProviderException::class,
        ];
    }

    public function testItRejectsBodyLargerThanConfiguredLimitWithoutPersistingIt(): void
    {
        $storage = $this->storage(new MockHttpClient(new MockResponse(str_repeat('x', 17), [
            'response_headers' => ['content-type: image/png'],
        ])), 16);

        $this->expectException(B2bPermanentProviderException::class);
        $storage->store('https://b2b.efeotoyedekparca.com.tr/large.png', 'Product');
    }

    public function testItRejectsRedirectBeforeContactingADisallowedHost(): void
    {
        $client = new MockHttpClient(new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['location: https://evil.example/image.png'],
        ]));
        $storage = $this->storage($client);

        try {
            $storage->store('https://b2b.efeotoyedekparca.com.tr/redirect.png', 'Product');
            self::fail('A redirect to a disallowed host must be rejected.');
        } catch (B2bPermanentProviderException) {
            self::assertSame(1, $client->getRequestsCount());
        }
    }

    public function testItRefusesToRemoveAFileOutsideItsManagedDirectory(): void
    {
        $outside = sys_get_temp_dir().'/efe-product-outside-'.bin2hex(random_bytes(4)).'.png';
        file_put_contents($outside, 'outside');
        $storage = $this->storage(new MockHttpClient());
        $stored = new \App\Module\Integration\B2b\StoredProductImage('/uploads/products/outside.png', $outside);
        try {
            $this->expectException(\InvalidArgumentException::class);
            $storage->remove($stored);
        } finally {
            @unlink($outside);
        }
    }

    private function storage(MockHttpClient $client, int $maxBytes = 5_000_000): ProductMediaStorage
    {
        return new ProductMediaStorage(
            httpClient: $client,
            directory: $this->directory,
            allowedHosts: ['b2b.efeotoyedekparca.com.tr'],
            maxBytes: $maxBytes,
            maxConnectDuration: 10,
            readTimeout: 30,
            overallTimeout: 60,
            maxRedirects: 3,
            caBundle: null,
        );
    }

    private function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg==', true);
    }

    private static function oversizedPngHeader(): string
    {
        $ihdr = pack('N', 6001).pack('N', 1)."\x08\x06\x00\x00\x00";
        $chunk = pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));

        return "\x89PNG\r\n\x1a\n".$chunk;
    }
}
