<?php

declare(strict_types=1);

namespace App\Module\Payment;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Builds the absolute public URLs a payment provider redirects the customer back to.
 *
 * These are generated from the configured public base URI rather than from the incoming
 * request. That matters for correctness and for safety: behind a TLS-terminating proxy the
 * request scheme is often plain http, and a Host header is attacker-controlled, so deriving
 * the return URL from the request would either break the redirect or let a caller aim the
 * provider's redirect at a host of their choosing.
 */
final readonly class PaymentPublicUrlFactory
{
    public function __construct(
        private RouterInterface $router,
        private string $publicBaseUri,
    ) {
        $base = rtrim(trim($this->publicBaseUri), '/');
        if (false === filter_var($base, FILTER_VALIDATE_URL) || '' === parse_url($base, PHP_URL_SCHEME) || '' === parse_url($base, PHP_URL_HOST)) {
            throw new \InvalidArgumentException('DEFAULT_URI must be an absolute URL for payment return addresses.');
        }
        $this->assertSecureBase($base);
    }

    public function publicBaseUri(): string
    {
        return rtrim(trim($this->publicBaseUri), '/');
    }

    /** @param array<string, scalar> $parameters */
    public function absolute(string $route, array $parameters = []): string
    {
        $path = $this->router->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);

        return $this->publicBaseUri().$path;
    }

    /**
     * A provider is never sent back over plain http: a return URL carries the order's payment
     * token, and an unencrypted leg of that redirect would expose it. Failing here, at the first
     * request that needs the URL, would be an opaque 500 after the order is already committed —
     * so an unusable base is refused outright.
     */
    private function assertSecureBase(string $base): void
    {
        if ('https' !== strtolower((string) parse_url($base, PHP_URL_SCHEME))) {
            throw new \InvalidArgumentException('DEFAULT_URI must use https for payment return addresses.');
        }
    }
}
