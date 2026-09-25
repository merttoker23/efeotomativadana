<?php

namespace App\Tests\Module\Settings;

use App\Entity\Commerce\StoreSetting;
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
        $requiredKeys = array_map(
            static fn (SettingKey $key): string => $key->value,
            SettingKey::cases(),
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
