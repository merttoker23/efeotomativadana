<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Response headers this application is willing to state, applied to every request.
 *
 * Each one is here because an earlier pass measured what the storefront and the admin
 * actually load, rather than because the header is conventional:
 *
 * - `Content-Security-Policy` is built from the measured origins. `script-src` keeps
 *   `'unsafe-inline'` because the theme's own pages carry inline event handlers and the
 *   PayTR card form is submitted from a page this store renders; removing it is a separate,
 *   deliberate piece of work, not a side effect of a security phase. What the policy does
 *   buy is a closed `default-src`, a host allowlist for scripts, images, styles, fonts and
 *   connections, `object-src 'none'` and `frame-ancestors 'none'`.
 * - `form-action` allows the payment provider's host and nothing else, because the card form
 *   posts the customer's browser straight to it.
 * - `Strict-Transport-Security` is production-only on purpose: sending it from a plain-http
 *   development host would pin that host to https for a browser for a year.
 *
 * X-Frame-Options is set as well as `frame-ancestors`, because not every client reads CSP
 * and one of the two being understood is worth more than both being present.
 *
 * **The listener also runs on `EXCEPTION`, and that is not belt-and-braces.** Symfony builds
 * the response for an uncaught exception and for a routing failure without dispatching
 * `KernelEvents::RESPONSE` at all, so a 405 or a 500 would otherwise be the only pages this
 * application serves with no `nosniff`, no framing policy and no content policy. The priority
 * is below `ErrorListener`'s, so the response it decorates is the final one.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -128)]
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -256)]
final class SecurityHeadersSubscriber
{
    /**
     * The payment provider the card form posts to. This mirrors the configured PayTR payment
     * URL's host, and a test asserts the two cannot drift: a `form-action` that does not name
     * the provider's host blocks a real payment, and one that names it wrongly is a hole.
     */
    public const string PAYMENT_ORIGIN = 'https://www.paytr.com';

    /**
     * The only third-party script host the application renders. It exists for the
     * FrankenPHP dev hot-reload snippet in the unreferenced skeleton layout, so it is
     * refused outside dev: in production nothing loads from it, and allowing it would
     * leave an unmeasured origin in the policy.
     */
    private const string DEV_SCRIPT_ORIGIN = 'https://cdn.jsdelivr.net';

    public function __construct(
        private readonly string $environment,
    ) {
    }

    public function __invoke(ResponseEvent|ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getResponse()->headers->add($this->headers());
    }

    /**
     * The complete header set this application is willing to state.
     *
     * Returned rather than applied in place so that the production variant can be asserted
     * directly, without booting a second container in a test environment. A security control
     * that can only be verified by switching environments tends not to be verified at all.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Content-Security-Policy' => $this->contentSecurityPolicy(),
        ];

        if ($this->isProduction()) {
            // Two years. The preload directive is deliberately absent: including subdomains is a
            // deployment decision that belongs to the operator, not to this application's
            // opinion about their other hosts.
            $headers['Strict-Transport-Security'] = 'max-age=63072000';
        }

        return $headers;
    }

    /**
     * The policy as a single header value.
     *
     * `data:` is allowed in `img-src` because the skeleton layout's favicon is an inline data
     * URI and the storefront renders uploaded images as data in a few previews; it is not
     * allowed in `script-src` or `object-src`, where it would be a way to run code.
     */
    public function contentSecurityPolicy(): string
    {
        $directives = [
            "default-src 'self'",
            // The theme's pages carry inline styles and the upload preview an inline style
            // attribute, so styles cannot be nonced here without a template rewrite.
            "style-src 'self' 'unsafe-inline'",
            // Same reason, deliberately kept: see the class docblock.
            "script-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            // Stimulus and Turbo only talk to this origin; the websocket schemes are here for
            // the dev live-reload transport, which is not rendered in production.
            "connect-src 'self' ws: wss:",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            // The only host a form on this store may submit to is this store and the
            // payment provider the card is handed to.
            "form-action 'self' ".self::PAYMENT_ORIGIN,
        ];

        if (!$this->isProduction()) {
            $directives[2] = "script-src 'self' 'unsafe-inline' ".self::DEV_SCRIPT_ORIGIN;
        }

        return implode('; ', $directives);
    }

    public function isProduction(): bool
    {
        return 'prod' === $this->environment;
    }
}
