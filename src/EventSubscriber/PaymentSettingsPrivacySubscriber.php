<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/** Disable collectors before the firewall as unauthenticated POSTs can also contain secrets. */
final readonly class PaymentSettingsPrivacySubscriber implements EventSubscriberInterface
{
    public function __construct(private ?Profiler $profiler = null)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return ['kernel.request' => ['onRequest', 16]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ('admin_settings_payment' === $event->getRequest()->attributes->get('_route')) {
            $this->profiler?->disable();
        }
    }
}
