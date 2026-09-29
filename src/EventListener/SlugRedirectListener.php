<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Seo\SeoResourceType;
use App\Module\Seo\SlugRedirectResolver;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

/**
 * Answers a retired public address with the permanent redirect that keeps it alive.
 *
 * This runs on the 404 rather than before the controller, which is what makes it both cheap
 * and complete: a visitor asking for an address that still resolves never reaches it, so the
 * history table is consulted only for addresses that genuinely no longer exist; and every
 * content type is covered without four storefront controllers each growing the same
 * "if not found, maybe it moved" branch.
 *
 * The paths are read from the router rather than written out here, so this keeps working if
 * the public prefix or a content type's path ever changes, and so it cannot drift into
 * recognising a path that no longer exists.
 *
 * Only a GET is answered. The content routes are themselves declared GET-only, so a POST is
 * refused by the router before this runs; the check here is defence in depth for a route that
 * later gains a non-GET method, where a redirect would make a browser replay a state-changing
 * request at a URL that no longer means the same thing.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 64)]
final readonly class SlugRedirectListener
{
    public function __construct(
        private RouterInterface $router,
        private SlugRedirectResolver $redirects,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof NotFoundHttpException) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod(Request::METHOD_GET)) {
            return;
        }

        $target = $this->retiredTargetFor($request->getPathInfo());
        if (null === $target) {
            return;
        }

        $event->setResponse(new RedirectResponse($target, Response::HTTP_MOVED_PERMANENTLY));
    }

    private function retiredTargetFor(string $path): ?string
    {
        $routes = $this->router->getRouteCollection();

        foreach (SeoResourceType::cases() as $resourceType) {
            $slug = $this->slugAt($routes->get($resourceType->routeName())?->getPath(), $path);
            if (null === $slug) {
                continue;
            }

            $redirect = $this->redirects->find($resourceType, $slug);

            return null === $redirect ? null : $this->redirects->targetUrlFor($redirect);
        }

        return null;
    }

    /**
     * The slug when `$path` is exactly this content type's public URL, otherwise null.
     *
     * The captured group is the same lowercase-hyphen shape the entities accept, so a path
     * that could never have been a slug is rejected here rather than looked up.
     */
    private function slugAt(?string $routePath, string $path): ?string
    {
        if (null === $routePath) {
            return null;
        }

        $pattern = str_replace(
            '\{slug\}',
            '([a-z0-9]+(?:-[a-z0-9]+)*)',
            preg_quote($routePath, '#'),
        );

        return 1 === preg_match('#^'.$pattern.'$#', $path, $matches) ? $matches[1] : null;
    }
}
