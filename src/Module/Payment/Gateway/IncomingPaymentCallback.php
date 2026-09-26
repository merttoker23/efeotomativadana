<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway;

/**
 * The unparsed callback exactly as it arrived.
 *
 * The raw body is preserved byte for byte because most provider signatures are computed
 * over the raw bytes; re-encoding parsed parameters would invalidate them.
 */
final readonly class IncomingPaymentCallback
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    public function __construct(
        private string $rawBody,
        private array $headers,
        private array $query,
        private string $returnToken = '',
    ) {
    }

    public function rawBody(): string { return $this->rawBody; }

    /**
     * The token from the URL the provider redirected to. A gateway that issues a reference only
     * when money moves needs it to tie an otherwise unreferenced report to one attempt.
     */
    public function returnToken(): string { return $this->returnToken; }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $header => $value) {
            if (0 === strcasecmp($header, $name)) {
                return $value;
            }
        }

        return null;
    }

    public function queryParameter(string $name): ?string
    {
        return $this->query[$name] ?? null;
    }
}
