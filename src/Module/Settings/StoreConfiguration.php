<?php

namespace App\Module\Settings;

use App\Module\Audit\AuditAction;
use App\Module\Audit\AuditLogger;
use App\Module\Integration\B2b\B2bProviderRegistry;
use App\Repository\Commerce\StoreSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The store's own settings, read through one typed door.
 *
 * Values are memoised for the length of a request and the memo is dropped again by
 * `kernel.reset` — which is what the Messenger worker triggers between messages too. That
 * matters because every page reads several of these (store name, locale, currency, tax rate,
 * the indexing switch), and each read is a query; the naive version spent four queries per
 * page proving the same four rows had not changed since the first one.
 *
 * It is deliberately *not* memoised for the lifetime of the process. FrankenPHP and the
 * Messenger worker both keep a PHP process alive across many requests, and a memo that
 * outlived one would show a worker the settings as they were when it started.
 */
final class StoreConfiguration implements ResetInterface
{
    /** @var array<string, bool|int|string|null> */
    private array $memo = [];

    public function __construct(
        private readonly StoreSettingRepository $settings,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly B2bProviderRegistry $b2bProviders,
        private readonly AuditLogger $audit,
    ) {
    }

    public function reset(): void
    {
        $this->memo = [];
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
            seoIndexingEnabled: $this->boolValue(SettingKey::SeoIndexingEnabled),
            seoDefaultDescription: $this->nullableStringValue(SettingKey::SeoDefaultDescription),
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
        $configuration->seoDefaultDescription = $this->normalizeText($configuration->seoDefaultDescription);

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
            SettingKey::SeoIndexingEnabled->value => $configuration->seoIndexingEnabled,
            SettingKey::SeoDefaultDescription->value => $configuration->seoDefaultDescription,
        ];

        // The diff is taken from the store's own current values, before anything is written,
        // so the audit row says what actually changed rather than what the form contained. A
        // settings form posts every key every time; without this, "the tax rate changed" would
        // be indistinguishable from "someone opened the settings page and pressed save".
        $before = $this->settings->allValues();
        $changes = [];
        foreach ($values as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changes[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
            }
        }

        foreach ($values as $key => $value) {
            $this->settings->put(SettingKey::from($key), $value);
        }

        $this->entityManager->flush();
        if ([] !== $changes) {
            $this->audit->record(AuditAction::SettingsUpdated, 'store_settings', ['changes' => $changes]);
            $this->entityManager->flush();
        }
        // Read-after-write has to see the write, from this process as well as the next request.
        $this->reset();
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

    public function isSeoIndexingEnabled(): bool
    {
        return $this->boolValue(SettingKey::SeoIndexingEnabled);
    }

    /**
     * The last-resort meta description. Null rather than a hard-coded sentence: inventing
     * marketing copy the merchant did not write is not this application's job, and the SEO
     * layer has its own final fallback when this is empty.
     */
    public function seoDefaultDescription(): ?string
    {
        return $this->nullableStringValue(SettingKey::SeoDefaultDescription);
    }

    private function value(SettingKey $key): mixed
    {
        if (array_key_exists($key->value, $this->memo)) {
            return $this->memo[$key->value];
        }

        return $this->memo[$key->value] = $this->settings->findOneByKey($key)?->value() ?? $key->defaultValue();
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

    /**
     * A blank optional text field arrives as an empty string, and storing that would publish
     * an empty description instead of leaving the page to fall back to its own text.
     */
    private function normalizeText(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }
}
