<?php

namespace App\Tests\Controller\Storefront;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class HomeControllerTest extends WebTestCase
{
    public function testHomeRendersTheAccessibleStorefrontShell(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/yeni/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="tr"]');
        self::assertSelectorTextContains('a.storefront-brand', 'Efe Otomotiv Adana');
        self::assertSelectorExists('header.site-header[data-controller~="storefront-shell"]');
        self::assertSelectorExists('.announcement[role="status"]');
        self::assertSelectorExists('nav[aria-label="Ana navigasyon"]');
        self::assertSelectorExists('button[aria-controls="mobile-navigation"][aria-expanded="false"]');
        self::assertSelectorExists('#mobile-navigation[hidden]');
        self::assertSelectorTextContains('main#main-content h1', 'Otomotiv parçasında güvenilir adresiniz');
        self::assertSelectorExists('footer.site-footer');

        self::assertSame('/yeni/', $crawler->filter('a.storefront-brand')->attr('href'));
        self::assertSame('h1', $crawler->filter('main h1, main h2')->first()->nodeName());
    }

    public function testHomeUsesMappedLocalAssetsWithoutStaticThemeLinks(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/yeni/');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(0, 'a[href$=".html"], form[action$=".html"]');
        self::assertStringNotContainsString('tema/', $client->getResponse()->getContent() ?: '');

        $stylesheetUrl = $this->requiredAttribute($crawler, 'link[data-storefront-stylesheet]', 'href');
        $heroImageUrl = $this->requiredAttribute($crawler, 'img.hero-image', 'src');
        $importMap = json_decode(
            $crawler->filter('script[type="importmap"]')->text(),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($importMap);
        self::assertIsArray($importMap['imports'] ?? null);
        $controllerUrl = $importMap['imports']['/yeni/assets/controllers/storefront_shell_controller.js'] ?? null;
        self::assertIsString($controllerUrl);

        $this->assertLocalAssetLoads($client, $stylesheetUrl, 'text/css');
        $this->assertLocalAssetLoads($client, $heroImageUrl, 'image/svg+xml');
        $this->assertLocalAssetLoads($client, $controllerUrl, 'text/javascript');
    }

    private function requiredAttribute(Crawler $crawler, string $selector, string $attribute): string
    {
        $node = $crawler->filter($selector);
        self::assertCount(1, $node);

        $value = $node->attr($attribute);
        self::assertNotNull($value);

        return $value;
    }

    private function assertLocalAssetLoads(KernelBrowser $client, string $url, string $contentType): void
    {
        self::assertStringStartsWith('/yeni/assets/', $url);
        self::assertStringNotContainsString('tema', $url);

        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', $contentType);
    }
}
