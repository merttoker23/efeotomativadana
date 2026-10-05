<?php

namespace App\Tests\Unit\Integration\B2b;

use App\Module\Integration\B2b\Provider\Efe\EfeBrandLogoSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EfeBrandLogoSourceTest extends TestCase
{
    public function testOnlyPublishedAllowedManufacturerLogoUrlsAreReturned(): void
    {
        $html = '<img src="img/markalar/31.jpg"><img src="img/markalar/31.jpg">'
            .'<img src="https://other.example/img/markalar/78.jpg"><img src="img/markalar/0.jpg">'
            .'<img src="urunler/78.jpg"><img src="https://b2b.efeotoyedekparca.com.tr:444/img/markalar/100.jpg">';
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($html): MockResponse {
            self::assertSame('https://b2b.efeotoyedekparca.com.tr/', $url);
            self::assertSame(0, $options['max_redirects']);
            return new MockResponse($html, ['response_headers' => ['content-type: text/html; charset=utf-8']]);
        });
        $source = new EfeBrandLogoSource($client, 'https://b2b.efeotoyedekparca.com.tr/feed.php', ['b2b.efeotoyedekparca.com.tr'], null);
        self::assertSame([31 => 'https://b2b.efeotoyedekparca.com.tr/img/markalar/31.jpg'], $source->urls());
    }

    public function testRedirectOrOversizedIndexIsRejected(): void
    {
        foreach ([new MockResponse('', ['http_code' => 302]), new MockResponse(str_repeat('x', 1_048_577), ['response_headers' => ['content-type: text/html']])] as $response) {
            $source = new EfeBrandLogoSource(new MockHttpClient($response), 'https://b2b.efeotoyedekparca.com.tr/feed.php', ['b2b.efeotoyedekparca.com.tr'], null);
            try {
                $source->urls();
                self::fail('Unsafe index must not be accepted.');
            } catch (\RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testEfePublishesLogoLinksInIts302HtmlWithoutNeedingToFollowTheRedirect(): void
    {
        $requests = 0;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            self::assertSame(0, $options['max_redirects']);
            ++$requests;
            self::assertSame('https://b2b.efeotoyedekparca.com.tr/', $url);
            return new MockResponse('<img src="img/markalar/31.jpg">', ['http_code' => 302, 'response_headers' => ['content-type: text/html', 'location: giris.php']]);
        });
        $source = new EfeBrandLogoSource($client, 'https://b2b.efeotoyedekparca.com.tr/feed.php', ['b2b.efeotoyedekparca.com.tr'], null);
        self::assertSame([31 => 'https://b2b.efeotoyedekparca.com.tr/img/markalar/31.jpg'], $source->urls());
        self::assertSame(1, $requests);

        $client = new MockHttpClient(new MockResponse('', ['http_code' => 302, 'response_headers' => ['content-type: text/html', 'location: https://other.example/index.html']]));
        $source = new EfeBrandLogoSource($client, 'https://b2b.efeotoyedekparca.com.tr/feed.php', ['b2b.efeotoyedekparca.com.tr'], null);
        self::assertSame([], $source->urls());
        self::assertSame(1, $client->getRequestsCount());
    }
}
