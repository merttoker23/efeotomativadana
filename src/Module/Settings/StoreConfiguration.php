<?php

namespace App\Module\Settings;

use App\Module\Integration\B2b\B2bProviderRegistry;
use App\Repository\Commerce\StoreSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class StoreConfiguration
{
    public function __construct(
        private StoreSettingRepository $settings,
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
        private B2bProviderRegistry $b2bProviders,
    ) {
    }

    public function current(): StoreSettingsData
    {
        return new StoreSettingsData(
            b2bEnabled: $this->boolValue(SettingKey::B2bEnabled),
            b2bProvider: $this->nullableStringValue(SettingKey::B2bProvider),
            storeName: $this->stringValue(SettingKey::StoreName),
            currency: $this->stringValue(SettingKey::StoreCurrency),
            defaultLocale: $this->stringValue(SettingKey::StoreDefaultLocale),
            defaultTaxRate: $this->intValue(SettingKey::StoreDefaultTaxRate),
            loyaltyEnabled: $this->boolValue(SettingKey::LoyaltyEnabled),
            loyaltyEarnPercentage: $this->intValue(SettingKey::LoyaltyEarnPercentage),
            paymentProvider: $this->nullableStringValue(SettingKey::PaymentProvider),
            shippingProvider: $this->nullableStringValue(SettingKey::ShippingProvider),
        );
    }

    public function save(StoreSettingsData $configuration): void
    {
        $configuration->b2bProvider = $this->normalizeProvider($configuration->b2bProvider);
        $configuration->paymentProvider = $this->normalizeProvider($configuration->paymentProvider);
        $configuration->shippingProvider = $this->normalizeProvider($configuration->shippingProvider);
        $configuration->storeName = trim($configuration->storeName);
        $configuration->currency = strtoupper(trim($configuration->currency));
        $configuration->defaultLocale = str_replace('_', '-', trim($configuration->defaultLocale));

        $violations = $this->validator->validate($configuration);
        if ($configuration->b2bEnabled && (null === $configuration->b2bProvider || !$this->b2bProviders->supports($configuration->b2bProvider))) {
            $violations->add(new ConstraintViolation(
                message: 'Enable a supported B2B provider when B2B integration is enabled.',
                messageTemplate: null,
                parameters: [],
                root: $configuration,
                propertyPath: 'b2bProvider',
                invalidValue: $configuration->b2bProvider,
                constraint: new NotBlank(),
            ));
        }
        if (count($violations) > 0) {
            throw new ValidationFailedException($configuration, $violations);
        }

        $values = [
            SettingKey::B2bEnabled->value => $configuration->b2bEnabled,
            SettingKey::B2bProvider->value => $configuration->b2bProvider,
            SettingKey::StoreName->value => $configuration->storeName,
            SettingKey::StoreCurrency->value => $configuration->currency,
            SettingKey::StoreDefaultLocale->value => $configuration->defaultLocale,
            SettingKey::StoreDefaultTaxRate->value => $configuration->defaultTaxRate,
            SettingKey::LoyaltyEnabled->value => $configuration->loyaltyEnabled,
            SettingKey::LoyaltyEarnPercentage->value => $configuration->loyaltyEarnPercentage,
            SettingKey::PaymentProvider->value => $configuration->paymentProvider,
            SettingKey::ShippingProvider->value => $configuration->shippingProvider,
        ];

        foreach ($values as $key => $value) {
            $this->settings->put(SettingKey::from($key), $value);
        }

        $this->entityManager->flush();
    }

    public function isB2bEnabled(): bool
    {
        return $this->boolValue(SettingKey::B2bEnabled);
    }

    public function b2bProvider(): ?string
    {
        return $this->nullableStringValue(SettingKey::B2bProvider);
    }

    public function storeName(): string
    {
        return $this->stringValue(SettingKey::StoreName);
    }

    public function currency(): string
    {
        return $this->stringValue(SettingKey::StoreCurrency);
    }

    public function defaultLocale(): string
    {
        return $this->stringValue(SettingKey::StoreDefaultLocale);
    }

    public function defaultTaxRate(): int
    {
        return $this->intValue(SettingKey::StoreDefaultTaxRate);
    }

    public function isLoyaltyEnabled(): bool
    {
        return $this->boolValue(SettingKey::LoyaltyEnabled);
    }

    public function loyaltyEarnPercentage(): int
    {
        return $this->intValue(SettingKey::LoyaltyEarnPercentage);
    }

    public function paymentProvider(): ?string
    {
        return $this->nullableStringValue(SettingKey::PaymentProvider);
    }

    public function shippingProvider(): ?string
    {
        return $this->nullableStringValue(SettingKey::ShippingProvider);
    }

    private function value(SettingKey $key): mixed
    {
        return $this->settings->findOneByKey($key)?->value() ?? $key->defaultValue();
    }

    private function boolValue(SettingKey $key): bool
    {
        $value = $this->value($key);
        if (!is_bool($value)) {
            throw new \UnexpectedValueException(sprintf('Setting "%s" must be a boolean.', $key->value));
        }

        return $value;
    }

    private function intValue(SettingKey $key): int
    {
        $value = $this->value($key);
        if (!is_int($value)) {
            throw new \UnexpectedValueException(sprintf('Setting "%s" must be an integer.', $key->value));
        }

        return $value;
    }

    private function stringValue(SettingKey $key): string
    {
        $value = $this->value($key);
        if (!is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Setting "%s" must be a string.', $key->value));
        }

        return $value;
    }

    private function nullableStringValue(SettingKey $key): ?string
    {
        $value = $this->value($key);
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Setting "%s" must be a string or null.', $key->value));
        }

        return $value;
    }

    private function normalizeProvider(?string $provider): ?string
    {
        $provider = null === $provider ? null : trim($provider);

        return '' === $provider ? null : $provider;
    }
}
