<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Module\Seo\SeoRoutePolicy;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Marks every response that belongs to a person, rather than to the catalogue, as noindex.
 *
 * Sent as a response header rather than a meta tag on purpose. The decision is made from the
 * route that actually handled the request, so it covers the account area, checkout, the cart,
 * the payment return addresses and the order pages without any of those controllers having to
 * remember to pass anything. A rule that depends on a controller remembering an argument is a
 * rule that fails on the one screen somebody forgot, and the failure is silent: the page keeps
 * working, it just gets indexed.
 *
 * A crawler honours `X-Robots-Tag` exactly as it honours the equivalent meta tag, so this is
 * the same instruction by a route that cannot be forgotten.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -64)]
final readonly class StorefrontIndexabilityListener
{
    public function __construct(
        private SeoRoutePolicy $policy,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (!$this->policy->isPrivate(is_string($route) ? $route : null)) {
            return;
        }

        $event->getResponse()->headers->set('X-Robots-Tag', 'noindex, follow');
    }
}
