<?php

declare(strict_types=1);

namespace App\Module\Payment;

use App\Module\Payment\Gateway\PaymentGatewayInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves the selected payment gateway and refuses to hand out an adapter that the current
 * environment is not allowed to use.
 *
 * The selection itself is the `payment.provider` store setting, passed in by the caller so
 * this class stays a pure, testable resolution rule.
 */
final class PaymentGatewayRegistry
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    /** @param iterable<PaymentGatewayInterface> $gateways */
    public function __construct(
        #[AutowireIterator('app.payment_gateway')] iterable $gateways,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
        foreach ($gateways as $gateway) {
            $key = mb_strtolower(trim($gateway->key()));
            if ('' === $key) {
                throw new \InvalidArgumentException('Payment gateway key must not be empty.');
            }
            if (isset($this->gateways[$key])) {
                throw new \InvalidArgumentException(sprintf('Duplicate payment gateway key "%s".', $key));
            }
            $this->gateways[$key] = $gateway;
        }
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function supports(string $key): bool
    {
        return isset($this->gateways[mb_strtolower(trim($key))]);
    }

    /**
     * The selected gateway, or null when none is configured. An unconfigured store is a
     * legitimate state (the payment option is simply not offered); an adapter that this
     * environment may not use is not.
     */
    public function resolve(?string $selectedKey): ?PaymentGatewayInterface
    {
        if (null === $selectedKey || '' === trim($selectedKey)) {
            return null;
        }
        $key = mb_strtolower(trim($selectedKey));
        $gateway = $this->gateways[$key] ?? throw new PaymentGatewayNotAvailable(sprintf('Payment provider "%s" is not installed.', $key));

        if (!$gateway->productionReady() && $this->isProduction()) {
            throw new PaymentGatewayNotAvailable(sprintf('Payment provider "%s" is not permitted in the prod environment.', $key));
        }

        return $gateway;
    }

    /** The selected gateway, for a flow that cannot proceed without one. */
    public function resolveOrFail(?string $selectedKey = null): PaymentGatewayInterface
    {
        $gateway = $this->resolve($selectedKey);
        if (null === $gateway) {
            throw new PaymentGatewayNotAvailable('No payment provider is configured.');
        }

        return $gateway;
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = array_keys($this->gateways);
        sort($keys);

        return $keys;
    }

    /**
     * The gateways an administrator may select in this environment. An adapter that is not
     * production ready is not offered, so a typo in `payment.provider` cannot leave checkout
     * offering a payment it can never take.
     *
     * @return list<string>
     */
    public function selectableKeys(): array
    {
        return array_values(array_filter(
            $this->keys(),
            fn (string $key): bool => $this->gateways[$key]->productionReady() || !$this->isProduction(),
        ));
    }

    /**
     * Anything that is not explicitly a development or test environment is treated as
     * production. A deployment named `staging` must not be handed a fake adapter either.
     */
    public function isProduction(): bool
    {
        return !in_array($this->environment, ['dev', 'test', 'local'], true);
    }
}
