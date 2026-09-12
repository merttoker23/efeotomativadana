<?php

namespace App\EventListener;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Profiler\Profiler;

class AssetMapperDevServerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AssetMapperInterface $assetMapper,
        private readonly ?Profiler $profiler = null,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $pathInfo = rawurldecode($event->getRequest()->getPathInfo());
        if (!str_starts_with($pathInfo, '/yeni/assets/')) {
            return;
        }

        $asset = $this->findAsset($pathInfo);
        if (!$asset) {
            return;
        }

        $this->profiler?->disable();

        if (null !== $asset->content) {
            $response = new Response($asset->content);
        } else {
            $response = new BinaryFileResponse($asset->sourcePath, autoLastModified: false);
        }
        $response
            ->setPublic()
            ->setMaxAge(604800)
            ->setImmutable()
            ->setEtag($asset->digest)
        ;
        if ($mediaType = $this->getMediaType($asset->publicPath)) {
            $response->headers->set('Content-Type', $mediaType);
        }
        $response->headers->set('X-Assets-Dev', '1');

        $event->setResponse($response);
        $event->stopPropagation();
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if ($event->getResponse()->headers->get('X-Assets-Dev')) {
            $event->stopPropagation();
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 36]],
            KernelEvents::RESPONSE => [['onKernelResponse', 2048]],
        ];
    }

    private function findAsset(string $pathInfo): ?\Symfony\Component\AssetMapper\MappedAsset
    {
        $publicPath = preg_replace('#^/yeni#', '', $pathInfo);
        if (null === $publicPath) {
            return null;
        }

        $logicalPath = ltrim(preg_replace('#^/assets/#', '', $publicPath) ?? '', '/');
        $undigestedPath = preg_replace('/-[-_0-9A-Za-z]{7}(\.[^\/.]+)$/', '$1', $logicalPath);
        $logicalPaths = [null === $undigestedPath ? $logicalPath : $undigestedPath];
        if (null !== $undigestedPath && $undigestedPath !== $logicalPath) {
            $logicalPaths[] = $logicalPath;
        }

        foreach ($logicalPaths as $candidatePath) {
            $asset = $this->assetMapper->getAsset($candidatePath);
            if (null !== $asset && $publicPath === $asset->publicPath) {
                return $asset;
            }
        }

        return null;
    }

    private function getMediaType(string $path): ?string
    {
        $extension = pathinfo($path, \PATHINFO_EXTENSION);
        return match ($extension) {
            'css' => 'text/css',
            'js' => 'text/javascript',
            default => null,
        };
    }
}
