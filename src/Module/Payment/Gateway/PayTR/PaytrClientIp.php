<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway\PayTR;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The customer's IP address, as PayTR requires it on every token request.
 *
 * Read per request and never invented: the provider rejects a private or local address, and a
 * substituted address would defeat the fraud signal the field exists to provide. The value
 * comes from Symfony's own client-IP resolution, so a forwarded header is only believed when
 * the proxy that sent it is trusted.
 */
final readonly class PaytrClientIp
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    public function customerIp(): ?string
    {
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp();

        return null === $ip ? null : trim($ip);
    }
}
