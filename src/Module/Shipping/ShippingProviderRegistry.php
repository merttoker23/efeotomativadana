<?php

declare(strict_types=1);

namespace App\Module\Shipping;

use App\Module\Shipping\Gateway\ShippingProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves the selected carrier and refuses to hand out an adapter the current environment is
 * not allowed to use.
 *
 * The selection itself is the `shipping.provider` store setting, passed in by the caller so this
 * class stays a pure, testable resolution rule. The production guard is deliberately the same
 * shape as the payment registry's: a test-only carrier that quietly stayed wired up would be a
 * store that believes it shipped a parcel nobody carries.
 */
final class ShippingProviderRegistry
{
    /** @var array<string, ShippingProviderInterface> */
    private array $providers = [];

    /** @param iterable<ShippingProviderInterface> $providers */
    public function __construct(
        #[AutowireIterator('app.shipping_provider')] iterable $providers,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
        foreach ($providers as $provider) {
            $key = mb_strtolower(trim($provider->key()));
            if ('' === $key) {
                throw new \InvalidArgumentException('Shipping provider key must not be empty.');
            }
            if (isset($this->providers[$key])) {
                throw new \InvalidArgumentException(sprintf('Duplicate shipping provider key "%s".', $key));
            }
            $this->providers[$key] = $provider;
        }
    }

    public function supports(string $key): bool
    {
        return isset($this->providers[mb_strtolower(trim($key))]);
    }

    /**
     * The selected carrier, or null when none is configured. A store without a carrier is a
     * legitimate state — it fulfils by hand — so an unconfigured selection is not a failure. An
     * adapter this environment may not use is.
     */
    public function resolve(?string $selectedKey): ?ShippingProviderInterface
    {
        if (null === $selectedKey || '' === trim($selectedKey)) {
            return null;
        }
        $key = mb_strtolower(trim($selectedKey));
        $provider = $this->providers[$key] ?? throw new ShippingProviderNotAvailable(sprintf('Shipping provider "%s" is not installed.', $key));

        if (!$provider->productionReady() && $this->isProduction()) {
            throw new ShippingProviderNotAvailable(sprintf('Shipping provider "%s" is not permitted in the %s environment.', $key, $this->environment));
        }

        return $provider;
    }

    /** The selected carrier, for a flow that cannot proceed without one. */
    public function resolveOrFail(?string $selectedKey = null): ShippingProviderInterface
    {
        $provider = $this->resolve($selectedKey);
        if (null === $provider) {
            throw new ShippingProviderNotAvailable('No shipping provider is configured.');
        }

        return $provider;
    }

    /**
     * Anything that is not explicitly a development or test environment is treated as production.
     * A deployment named `staging` must not be handed a fake carrier either.
     */
    public function isProduction(): bool
    {
        return !in_array($this->environment, ['dev', 'test', 'local'], true);
    }
}
