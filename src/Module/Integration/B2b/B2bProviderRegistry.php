<?php

namespace App\Module\Integration\B2b;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class B2bProviderRegistry
{
    /** @var array<string, B2bFeedProviderInterface> */
    private array $providers = [];

    /** @param iterable<B2bFeedProviderInterface> $providers */
    public function __construct(#[AutowireIterator('app.b2b_feed_provider')] iterable $providers)
    {
        foreach ($providers as $provider) {
            $key = mb_strtolower(trim($provider->providerKey()));
            if ('' === $key) {
                throw new \InvalidArgumentException('B2B provider key must not be empty.');
            }
            if (isset($this->providers[$key])) {
                throw new \InvalidArgumentException(sprintf('Duplicate B2B provider key "%s".', $key));
            }
            $this->providers[$key] = $provider;
        }
    }

    public function supports(string $key): bool
    {
        return isset($this->providers[mb_strtolower(trim($key))]);
    }

    public function get(string $key): B2bFeedProviderInterface
    {
        $key = mb_strtolower(trim($key));
        if (!isset($this->providers[$key])) {
            throw new \InvalidArgumentException(sprintf('Unsupported B2B provider "%s".', $key));
        }

        return $this->providers[$key];
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = array_keys($this->providers);
        sort($keys);

        return $keys;
    }
}
