<?php

declare(strict_types=1);

namespace App\Shared;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Builds absolute public URLs from the configured public base URI.
 *
 * Nothing here reads the incoming request. That is the whole point: a `Host` header is supplied
 * by whoever is making the request, so a link built from it — a password reset link, a payment
 * return address — can be aimed at a host the store does not control. Behind a TLS-terminating
 * proxy the request scheme is also often plain http, so the same derivation produces a link that
 * silently stops being secure.
 *
 * A base that is not absolute https is refused outright rather than at first use, because the
 * alternative is an opaque failure much later, in the middle of a customer's password reset.
 */
final readonly class PublicUrlGenerator
{
    public function __construct(
        private RouterInterface $router,
        private string $publicBaseUri,
    ) {
        $base = rtrim(trim($this->publicBaseUri), '/');
        if (false === filter_var($base, \FILTER_VALIDATE_URL) || '' === parse_url($base, \PHP_URL_SCHEME) || '' === parse_url($base, \PHP_URL_HOST)) {
            throw new \InvalidArgumentException('DEFAULT_URI must be an absolute URL for public links.');
        }
        if ('https' !== strtolower((string) parse_url($base, \PHP_URL_SCHEME))) {
            throw new \InvalidArgumentException('DEFAULT_URI must use https for public links.');
        }
    }

    public function publicBaseUri(): string
    {
        return rtrim(trim($this->publicBaseUri), '/');
    }

    /** @param array<string, scalar> $parameters */
    public function absolute(string $route, array $parameters = []): string
    {
        return $this->publicBaseUri().$this->router->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);
    }
}
