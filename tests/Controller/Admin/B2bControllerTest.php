<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Customer\AdminUser;
use App\Entity\Integration\B2bSyncError;
use App\Entity\Integration\B2bSyncRun;
use App\Module\Integration\B2b\B2bErrorType;
use App\Module\Integration\B2b\B2bSyncCounters;
use App\Module\Integration\B2b\B2bSyncMode;
use App\Module\Settings\SettingKey;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class B2bControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->resetDatabaseState();
    }

    protected function tearDown(): void
    {
        $this->resetDatabaseState();

        parent::tearDown();
    }

    public function testAnonymousUserCannotAccessB2bOperations(): void
    {
        $this->client->request('GET', '/yeni/admin/integration/b2b');

        self::assertResponseRedirects('/yeni/admin/login');
    }

    public function testNonAdminUserCannotAccessB2bOperations(): void
    {
        $this->client->loginUser(new InMemoryUser('viewer@example.com', 'test-only-not-used-for-form-login', ['ROLE_USER']), 'admin');

        $this->client->request('GET', '/yeni/admin/integration/b2b');

        self::assertResponseStatusCodeSame(403);
    }

    public function testActionsArePostOnlyAndRequireTheSharedCsrfToken(): void
    {
        $this->client->loginUser($this->createAdministrator(), 'admin');

        $this->client->request('GET', '/yeni/admin/integration/b2b/full');
        self::assertResponseStatusCodeSame(405);

        $this->client->request('POST', '/yeni/admin/integration/b2b/daily', ['_token' => 'invalid-token']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->rowCount('integration_b2b_sync_run'));
    }

    public function testDisabledIntegrationIsANoOp(): void
    {
        $this->client->loginUser($this->createAdministrator(), 'admin');
        $token = $this->csrfToken();
        self::assertCount(2, $this->client->getCrawler()->filter('.integration-actions button[disabled]'));

        $this->client->request('POST', '/yeni/admin/integration/b2b/full', ['_token' => $token]);

        self::assertResponseRedirects('/yeni/admin/integration/b2b');
        self::assertSame(0, $this->rowCount('integration_b2b_sync_run'));
        self::assertSame(0, $this->rowCount('messenger_messages'));
    }

    public function testEnabledActionsQueueOneRunAndCoalesceTheSecondMode(): void
    {
        $this->client->loginUser($this->createAdministrator(), 'admin');
        $this->enableB2b();
        $token = $this->csrfToken();
        self::assertCount(0, $this->client->getCrawler()->filter('.integration-actions button[disabled]'));

        $this->client->request('POST', '/yeni/admin/integration/b2b/full', ['_token' => $token]);
        self::assertResponseRedirects('/yeni/admin/integration/b2b');
        self::assertSame(1, $this->rowCount('integration_b2b_sync_run'));
        self::assertSame(1, $this->rowCount('messenger_messages'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'was queued');
        self::assertCount(2, $this->client->getCrawler()->filter('.integration-actions button[disabled]'));

        $this->client->request('POST', '/yeni/admin/integration/b2b/daily', ['_token' => $token]);
        self::assertResponseRedirects('/yeni/admin/integration/b2b');
        self::assertSame(1, $this->rowCount('integration_b2b_sync_run'));
        self::assertSame(1, $this->rowCount('messenger_messages'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'already active');
    }

    public function testPageRendersBoundedRunProgressAndCountersWithoutRecordedErrorTables(): void
    {
        $this->client->loginUser($this->createAdministrator(), 'admin');
        $this->enableB2b();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable('2026-09-25T12:00:00+00:00');
        $run = B2bSyncRun::queue('efe', B2bSyncMode::Full, $now);
        $run->markRunning($now);
        $run->recordSnapshot('/var/b2b-snapshots/super-secret-snapshot.json', 12, str_repeat('b', 64), $now);
        $run->recordBatch(
            B2bSyncCounters::empty()
                ->recordScanned(12)
                ->recordCreated(3)
                ->recordPriceUpdated(3)
                ->recordStockUpdated(3),
            12,
            $now,
        );
        $entityManager->persist($run);
        $entityManager->persist(B2bSyncError::create(
            $run,
            'ERR-1001',
            B2bErrorType::InvalidPrice,
            false,
            'The provider price is invalid.',
            ['raw' => 'FULL_PROVIDER_PAYLOAD'],
            $now,
        ));
        $entityManager->flush();
        $runId = $run->id();
        self::assertNotNull($runId);

        $this->client->request('GET', '/yeni/admin/integration/b2b', ['run_id' => $runId]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="provider-host"]', 'b2b.efeotoyedekparca.com.tr');
        self::assertSelectorTextContains('[data-testid="counter-scanned"]', '12');
        self::assertSelectorTextContains('[data-testid="counter-created"]', '3');
        self::assertSelectorTextContains('[data-testid="counter-price_updated"]', '3');
        self::assertSelectorTextContains('[data-testid="counter-stock_updated"]', '3');
        self::assertSelectorExists('[data-testid="active-run"]');
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('ERR-1001', $content);
        self::assertStringNotContainsString('The provider price is invalid.', $content);
        self::assertSelectorNotExists('[data-testid="b2b-error-row"]');
        self::assertStringNotContainsString('super-secret-snapshot.json', $content);
        self::assertStringNotContainsString('FULL_PROVIDER_PAYLOAD', $content);
        self::assertStringNotContainsString('json-tum-gercek-stoklar.php', $content);
    }

    /**
     * A finished run's failure reason is what makes a failed FULL run diagnosable, so the run's
     * own latest_error must stay visible even though the recorded error table is gone.
     */
    public function testPageShowsTheSelectedRunsOwnFailureReason(): void
    {
        $this->client->loginUser($this->createAdministrator(), 'admin');
        $this->enableB2b();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable('2026-09-25T12:00:00+00:00');
        $run = B2bSyncRun::queue('efe', B2bSyncMode::Full, $now);
        $run->markRunning($now);
        $run->fail('Product image transport timed out.', $now);
        $entityManager->persist($run);
        $entityManager->flush();
        $runId = $run->id();
        self::assertNotNull($runId);

        $this->client->request('GET', '/yeni/admin/integration/b2b', ['run_id' => $runId]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="selected-run-error"]', 'Product image transport timed out.');
    }

    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/yeni/admin/integration/b2b');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[data-testid="b2b-full-form"] input[name="_token"]')->attr('value');
        self::assertIsString($token);

        return $token;
    }

    private function createAdministrator(): AdminUser
    {
        $administrator = new AdminUser('b2b-admin@example.com');
        $administrator->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword(
                $administrator,
                'VeryStrong!123',
            ),
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($administrator);
        $entityManager->flush();

        return $administrator;
    }

    private function enableB2b(): void
    {
        $this->connection->update('store_setting', ['value' => 'true'], ['setting_key' => SettingKey::B2bEnabled->value]);
        $this->connection->update('store_setting', ['value' => '"efe"'], ['setting_key' => SettingKey::B2bProvider->value]);
    }

    private function rowCount(string $table): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
    }

    private function resetDatabaseState(): void
    {
        $this->connection->executeStatement('DELETE FROM messenger_messages');
        $this->connection->executeStatement('DELETE FROM integration_b2b_sync_error');
        $this->connection->executeStatement('DELETE FROM integration_b2b_sync_run');
        $this->connection->executeStatement('DELETE FROM admin_user');
        $this->connection->executeStatement('DELETE FROM store_setting');

        foreach (SettingKey::cases() as $key) {
            $this->connection->insert('store_setting', [
                'setting_key' => $key->value,
                'value' => json_encode($key->defaultValue(), JSON_THROW_ON_ERROR),
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }
    }
}
