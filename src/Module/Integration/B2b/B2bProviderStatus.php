<?php

namespace App\Module\Integration\B2b;

final readonly class B2bProviderStatus
{
    private function __construct(
        private string $providerKey,
        private bool $configured,
        private string $endpointHost,
    ) {
    }

    /** @param list<string> $allowedHosts */
    public static function fromEndpoint(string $providerKey, string $endpointUrl, array $allowedHosts = []): self
    {
        $providerKey = mb_strtolower(trim($providerKey));
        $host = parse_url($endpointUrl, PHP_URL_HOST);
        $host = is_string($host) ? mb_strtolower($host) : '';
        $allowed = [];
        foreach ($allowedHosts as $allowedHost) {
            $allowed[mb_strtolower(trim($allowedHost))] = true;
        }
        $port = parse_url($endpointUrl, PHP_URL_PORT);
        $configured = '' !== $providerKey
            && '' !== $host
            && ('https' === strtolower((string) parse_url($endpointUrl, PHP_URL_SCHEME)))
            && ([] === $allowed || isset($allowed[$host]))
            && (null === $port || 443 === (int) $port)
            && null === parse_url($endpointUrl, PHP_URL_USER)
            && null === parse_url($endpointUrl, PHP_URL_PASS);

        return new self($providerKey, $configured, $host);
    }

    public function providerKey(): string { return $this->providerKey; }
    public function configured(): bool { return $this->configured; }
    public function endpointHost(): string { return $this->endpointHost; }
}
