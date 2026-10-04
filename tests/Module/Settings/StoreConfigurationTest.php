<?php

namespace App\Tests\Module\Settings;

use App\Entity\Commerce\StoreSetting;
use App\Module\Audit\AuditLogger;
use App\Module\Settings\SettingKey;
use App\Module\Integration\B2b\B2bFeedProviderInterface;
use App\Module\Integration\B2b\B2bProviderRegistry;
use App\Module\Integration\B2b\B2bProviderStatus;
use App\Module\Integration\B2b\B2bSnapshot;
use App\Module\Integration\B2b\B2bSnapshotRequest;
use App\Module\Integration\B2b\B2bSyncCheckpoint;
use App\Module\Settings\StoreConfiguration;
use App\Repository\Commerce\StoreSettingRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class StoreConfigurationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testRequiredSettingsArePersistedExactlyOnce(): void
    {
        $persistedKeys = $this->connection->fetchFirstColumn('SELECT setting_key FROM store_setting ORDER BY setting_key');
        // Colors, Analytics, cookie code and shipping amounts use defaults until saved.
        $optional = static fn (string $key): bool => str_starts_with($key, 'storefront.color.') || str_starts_with($key, 'analytics.') || in_array($key, [SettingKey::ShippingFee->value, SettingKey::FreeShippingThreshold->value, SettingKey::CookieScript->value], true);
        $persistedKeys = array_values(array_filter($persistedKeys, static fn (string $key): bool => !$optional($key)));
        $requiredKeys = array_map(
            static fn (SettingKey $key): string => $key->value,
            array_values(array_filter(SettingKey::cases(), static fn (SettingKey $key): bool => !$optional($key->value))),
        );
        sort($requiredKeys);

        self::assertSame($requiredKeys, $persistedKeys);
        self::assertSame(
            'false',
            $this->connection->fetchOne("SELECT value FROM store_setting WHERE setting_key = 'commerce.b2b_enabled'"),
        );
    }

    public function testB2bDefaultsToDisabledWhenItsPersistedRowIsMissing(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM store_setting WHERE setting_key = ?',
            [SettingKey::B2bEnabled->value],
        );

        self::assertFalse($this->configuration()->isB2bEnabled());
    }

    public function testCookieSettingsUseNullDefaultAndValidateOutsideTheAdminForm(): void
    {
        $this->connection->executeStatement('DELETE FROM store_setting WHERE setting_key = ?', [SettingKey::CookieScript->value]);
        $configuration = $this->configuration();
        self::assertNull($configuration->cookieScript());
        $snippet = '<script>window.cookieTest = true;</script>';
        $configuration->saveCookies(new \App\Module\Settings\CookieSettingsData('  '.$snippet.'  '));
        self::getContainer()->get('doctrine')->getManager()->clear();
        self::assertSame($snippet, $configuration->currentCookies()->script);
        try {
            $configuration->saveCookies(new \App\Module\Settings\CookieSettingsData(str_repeat('x', 50001)));
            self::fail('Oversize cookie code must be rejected at the persistence boundary.');
        } catch (ValidationFailedException) {
            self::assertSame($snippet, $configuration->cookieScript());
        }
        $configuration->saveCookies(new \App\Module\Settings\CookieSettingsData(" \n\t"));
        self::assertNull($configuration->cookieScript());
    }

    public function testTypedSettingsPersistAcrossEntityManagerClear(): void
    {
        $settings = $this->configuration()->current();
        $settings->b2bEnabled = true;
        $settings->b2bProvider = 'efe';
        $settings->storeName = 'Efe Otomotiv Test';

        $this->configuration()->save($settings);
        self::getContainer()->get('doctrine')->getManager()->clear();

        self::assertTrue($this->configuration()->isB2bEnabled());
        self::assertSame('Efe Otomotiv Test', $this->configuration()->storeName());
    }

    public function testEnabledB2bRequiresAProvider(): void
    {
        $settings = $this->configuration()->current();
        $settings->b2bEnabled = true;
        $settings->b2bProvider = null;

        try {
            $this->configuration()->save($settings);
            self::fail('Enabled B2B without a provider must be rejected.');
        } catch (ValidationFailedException) {
            self::assertFalse($this->configuration()->isB2bEnabled());
            self::assertNull($this->configuration()->b2bProvider());
        }
    }

    public function testEnabledB2bRejectsAnUnsupportedProvider(): void
    {
        $settings = $this->configuration()->current();
        $settings->b2bEnabled = true;
        $settings->b2bProvider = 'unsupported';

        $this->expectException(ValidationFailedException::class);
        $this->configuration()->save($settings);
    }

    public function testInvalidPercentageIsRejectedWithoutPersistence(): void
    {
        $settings = $this->configuration()->current();
        $settings->defaultTaxRate = 101;

        try {
            $this->configuration()->save($settings);
            self::fail('An invalid tax rate must be rejected.');
        } catch (ValidationFailedException) {
            self::getContainer()->get('doctrine')->getManager()->clear();
            self::assertSame(20, $this->configuration()->defaultTaxRate());
        }
    }

    public function testMissingAndInvalidStoredColorsFallBackWithoutAMigration(): void
    {
        $this->connection->executeStatement("DELETE FROM store_setting WHERE setting_key LIKE 'storefront.color.%'");
        $defaults = [
            'notice' => '#071e3c', 'navy' => '#092a53', 'navy-light' => '#123d70',
            'yellow' => '#fed243', 'body' => '#ebebf0', 'card' => '#ffffff',
            'ink' => '#171c22', 'muted' => '#69717a', 'line' => '#e1e3e6',
        ];
        self::assertSame($defaults, $this->configuration()->storefrontColors());

        foreach (['#fff', '#1234567', '#123456; color:red', "#123456\n", '', null, 123456, false] as $invalid) {
            $this->connection->executeStatement("DELETE FROM store_setting WHERE setting_key = 'storefront.color.navy'");
            $this->connection->insert('store_setting', [
                'setting_key' => 'storefront.color.navy',
                'value' => json_encode($invalid, JSON_THROW_ON_ERROR),
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
            self::getContainer()->get('doctrine')->getManager()->clear();
            self::assertSame($defaults, $this->configuration()->storefrontColors());
        }
    }

    private function configuration(): StoreConfiguration
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $repository = $entityManager->getRepository(StoreSetting::class);
        self::assertInstanceOf(StoreSettingRepository::class, $repository);

        return new StoreConfiguration(
            $repository,
            $entityManager,
            self::getContainer()->get(ValidatorInterface::class),
            new B2bProviderRegistry([new SettingsTestProvider()]),
            self::getContainer()->get(AuditLogger::class),
        );
    }
}

final class SettingsTestProvider implements B2bFeedProviderInterface
{
    public function providerKey(): string { return 'efe'; }
    public function status(): B2bProviderStatus { return B2bProviderStatus::fromEndpoint('efe', 'https://example.com/feed'); }
    public function prepareSnapshot(B2bSnapshotRequest $request): B2bSnapshot { throw new \LogicException(); }
    public function streamItems(B2bSnapshot $snapshot, B2bSyncCheckpoint $checkpoint): iterable { return []; }
}
