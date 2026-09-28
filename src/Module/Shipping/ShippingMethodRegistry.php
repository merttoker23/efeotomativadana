<?php

declare(strict_types=1);

namespace App\Module\Shipping;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves the delivery service an order named into the rule that decides who performs it.
 *
 * This is the selection rule the whole shipping side is built on, and it is deliberately a pure
 * function of the registered methods: an order may only be fulfilled by a method the store
 * actually offers. An order naming a method nothing implements — a retired service, a typo, a
 * config that changed — is refused loudly rather than quietly sent somewhere unexpected.
 *
 * Provider resolution is not done here. Keeping this class free of the carrier registry is what
 * lets it be tested without a container and keeps "which services exist" separate from "which
 * carrier is installed".
 */
final class ShippingMethodRegistry
{
    /** @var array<string, ShippingMethodInterface> */
    private array $methods = [];

    /** @param iterable<ShippingMethodInterface> $methods */
    public function __construct(
        #[AutowireIterator('app.shipping_method')] iterable $methods,
    ) {
        foreach ($methods as $method) {
            $key = mb_strtolower(trim($method->key()));
            if ('' === $key) {
                throw new \InvalidArgumentException('Shipping method key must not be empty.');
            }
            if (isset($this->methods[$key])) {
                throw new \InvalidArgumentException(sprintf('Duplicate shipping method key "%s".', $key));
            }
            $this->methods[$key] = $method;
        }
    }

    public function supports(string $key): bool
    {
        return isset($this->methods[mb_strtolower(trim($key))]);
    }

    public function resolve(?string $key): ?ShippingMethodInterface
    {
        if (null === $key || '' === trim($key)) {
            return null;
        }

        return $this->methods[mb_strtolower(trim($key))] ?? null;
    }

    /** The method an order named, or a refusal explaining why it cannot be fulfilled. */
    public function select(?string $key): ShippingMethodInterface
    {
        $method = $this->resolve($key);
        if (null !== $method) {
            return $method;
        }

        throw new ShippingMethodNotAvailable(null === $key || '' === trim($key)
            ? 'No shipping method is configured.'
            : sprintf('Shipping method "%s" is not installed.', mb_strtolower(trim($key))));
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = array_keys($this->methods);
        sort($keys);

        return $keys;
    }
}
