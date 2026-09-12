<?php

namespace App\Tests\EventListener;

use App\EventListener\AssetMapperDevServerSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\MappedAsset;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AssetMapperDevServerSubscriberTest extends TestCase
{
    public function testItServesAFingerprintedAssetWithoutScanningTheEntireAssetMap(): void
    {
        $assetMapper = $this->createMock(AssetMapperInterface::class);
        $assetMapper
            ->expects(self::once())
            ->method('getAsset')
            ->with('styles/admin.css')
            ->willReturn(new MappedAsset(
                logicalPath: 'styles/admin.css',
                sourcePath: __FILE__,
                publicPathWithoutDigest: '/assets/styles/admin.css',
                publicPath: '/assets/styles/admin-23MTFBz.css',
                content: ':root { color-scheme: light; }',
                digest: 'test-digest',
                isPredigested: false,
            ));
        $assetMapper
            ->expects(self::never())
            ->method('allAssets');

        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new RequestEvent(
            $kernel,
            Request::create('/yeni/assets/styles/admin-23MTFBz.css'),
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new AssetMapperDevServerSubscriber($assetMapper))->onKernelRequest($event);

        self::assertNotNull($event->getResponse());
        self::assertSame(':root { color-scheme: light; }', $event->getResponse()->getContent());
        self::assertSame('text/css', $event->getResponse()->headers->get('Content-Type'));
        self::assertSame('1', $event->getResponse()->headers->get('X-Assets-Dev'));
    }
}
