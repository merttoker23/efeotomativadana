<?php

namespace App\Tests\Unit\Integration\B2b;

use App\Module\Catalog\BrandLogoStorage;
use App\Module\Integration\B2b\B2bBrandLogoSynchronizer;
use App\Module\Integration\B2b\ProductMediaStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Validator\Validation;

final class B2bBrandLogoSynchronizerTest extends TestCase
{
    private string $directory;
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/efe-logo-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }
    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }
    public function testStoresByLocalIdDeduplicatesAndUsesConditionalRequestsAcrossRuns(): void
    {
        $requests = 0;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            ++$requests;
            if (2 === $requests) {
                self::assertContains('If-None-Match: "logo-v1"', $options['headers']);
                return new MockResponse('', ['http_code' => 304]);
            }
            return new MockResponse($this->png(), ['response_headers' => ['etag: "logo-v1"']]);
        });
        $logos = new BrandLogoStorage($this->directory.'/brands', Validation::createValidator());
        $sync = $this->sync($client, $logos);
        self::assertNull($sync->sync(900, 'https://b2b.efeotoyedekparca.com.tr/img/markalar/31.jpg', 'manufacturer:31', 1));
        self::assertNotNull($logos->uploadedPath(900));
        self::assertNull($logos->uploadedPath(31));
        $before = $logos->snapshot(900);
        for ($i = 0; $i < 100; ++$i) $sync->sync(900, 'https://b2b.efeotoyedekparca.com.tr/img/markalar/31.jpg', 'manufacturer:31', 1);
        self::assertSame(1, $requests);
        self::assertNull($sync->sync(900, 'https://b2b.efeotoyedekparca.com.tr/img/markalar/31.jpg', 'manufacturer:31', 2));
        self::assertSame(2, $requests);
        self::assertSame($before, $logos->snapshot(900));
        self::assertSame([], glob($this->directory.'/products/*'));
    }
    public function testBadLogoReturnsOneDeferredErrorAndDoesNotLeaveFiles(): void
    {
        $client = new MockHttpClient(new MockResponse('not an image'));
        $logos = new BrandLogoStorage($this->directory.'/brands', Validation::createValidator());
        $sync = $this->sync($client, $logos);
        self::assertNotNull($sync->sync(900, 'https://b2b.efeotoyedekparca.com.tr/img/markalar/31.jpg', 'manufacturer:31', 1));
        self::assertNull($sync->sync(900, 'https://b2b.efeotoyedekparca.com.tr/img/markalar/31.jpg', 'manufacturer:31', 1));
        self::assertNull($logos->uploadedPath(900));
        self::assertSame(1, $client->getRequestsCount());
    }
    public function testBatchRollbackRestoresPreviousLogoAndAllowsRetry(): void
    {
        $logos = new BrandLogoStorage($this->directory.'/brands', Validation::createValidator());
        $client = new MockHttpClient(fn () => new MockResponse($this->png()));
        $sync = $this->sync($client, $logos);
        $sync->beginTransaction();
        $sync->sync(900, 'https://b2b.efeotoyedekparca.com.tr/img/markalar/31.jpg', 'manufacturer:31', 1);
        self::assertNotNull($logos->uploadedPath(900));
        $sync->rollback();
        self::assertNull($logos->uploadedPath(900));
        $sync->sync(900, 'https://b2b.efeotoyedekparca.com.tr/img/markalar/31.jpg', 'manufacturer:31', 1);
        self::assertNotNull($logos->uploadedPath(900));
        self::assertSame(2, $client->getRequestsCount());
    }
    public function testFailedDownloadIsReportedAgainAfterBatchRollback(): void
    {
        $client = new MockHttpClient(fn () => new MockResponse('bad image', ['http_code' => 404]));
        $logos = new BrandLogoStorage($this->directory.'/brands', Validation::createValidator());
        $sync = $this->sync($client, $logos);
        $sync->beginTransaction();
        self::assertNotNull($sync->sync(900, 'https://b2b.efeotoyedekparca.com.tr/img/markalar/31.jpg', 'manufacturer:31', 1));
        $sync->rollback();
        self::assertNotNull($sync->sync(900, 'https://b2b.efeotoyedekparca.com.tr/img/markalar/31.jpg', 'manufacturer:31', 1));
        self::assertSame(2, $client->getRequestsCount());
    }
    private function sync(MockHttpClient $client, BrandLogoStorage $logos): B2bBrandLogoSynchronizer
    {
        return new B2bBrandLogoSynchronizer(new ProductMediaStorage($client, $this->directory.'/products', ['b2b.efeotoyedekparca.com.tr'], 5_000_000, 10, 30, 60, 3, null), $logos, new ArrayAdapter());
    }
    private function png(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        return (string) ob_get_clean();
    }
}
