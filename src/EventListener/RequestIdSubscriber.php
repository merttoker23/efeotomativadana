<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * One identifier per request, shared by the audit trail and the response.
 *
 * This exists because "who changed the tax rate" is answered by two records — an audit row and a
 * log line about the same save — and correlating them by timestamp is guesswork. A request id makes
 * the correlation exact, and lets a customer quote it from a support conversation.
 *
 * **The identifier is always generated here and never taken from the request.** Honouring a
 * caller-supplied `X-Request-Id` is the obvious thing to do — a load balancer already has one,
 * and copying it keeps a single identifier across the whole request path — and it was the first
 * version of this class. It was removed because this id is an *audit correlation key*: an attacker
 * who chooses it can collide two unrelated requests, or make one request look like part of another
 * flow, and an audit trail whose grouping key is attacker-chosen is weaker than one with no
 * grouping at all. A random 32-character hex value costs nothing to generate and cannot be aimed.
 *
 * The value is bounded in any case, because it is echoed back in a response header and an
 * unbounded caller-controlled string would be reflected verbatim. The bound is now defence in
 * depth rather than the primary control.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -160)]
final class RequestIdSubscriber
{
    public const string ATTRIBUTE = '_audit_request_id';
    public const string HEADER = 'X-Request-Id';

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->attributes->set(self::ATTRIBUTE, $this->generate());
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requestId = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if (is_string($requestId) && '' !== $requestId) {
            $event->getResponse()->headers->set(self::HEADER, $requestId);
        }
    }

    /** 32 hex characters: unambiguous in a log, unguessable, and well inside any header bound. */
    private function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
