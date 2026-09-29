<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Commerce\AuditLog;
use App\Entity\Customer\AdminUser;
use App\Module\Audit\AuditAction;
use App\Module\Audit\AuditActorType;
use App\Module\Audit\AuditContext;
use App\Module\Audit\AuditLogger;
use App\Module\Settings\SettingKey;
use App\Tests\ResetsRateLimits;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The audit trail, checked as an instrument rather than as a feature.
 *
 * Three questions, in order of how much they matter:
 *
 * 1. **Is anything actually recorded?** A trail that is defined but never written to is worse
 *    than none, because it looks like an answer during an investigation. So these cases drive
 *    the real endpoints and then read the rows back.
 * 2. **Is it attributable?** Actor, address and resource, or the row is decoration.
 * 3. **Does recording ever harm the business action?** A logger that can refuse a captured
 *    payment would be a liability, and that property is asserted rather than assumed.
 */
final class AuditTrailTest extends WebTestCase
{
    use ResetsRateLimits;

    private const string PASSWORD = 'VeryStrong!123';

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        // This class signs in through the form, and so does the rate limit suite; both firewalls
        // keep a shared budget across a run.
        $this->resetRateLimits();
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
        parent::tearDown();
    }

    public function testAStoreSettingsChangeIsRecordedWithAKeyByKeyDiff(): void
    {
        $this->loginAsAdministrator();

        $crawler = $this->client->request('GET', '/yeni/admin/settings');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Save')->form();
        $form['store_settings[storeName]'] = 'Efe Otomotiv Adana';
        // Only the tax rate is changed; every other key is resubmitted with its current value,
        // which is exactly why a raw form dump would be useless as an audit record.
        $form['store_settings[defaultTaxRate]'] = '22';
        $this->client->submit($form);
        self::assertResponseRedirects('/yeni/admin/settings');

        $row = $this->lastRowFor(AuditAction::SettingsUpdated);
        self::assertNotNull($row, 'Changing the tax rate produced no audit row.');
        self::assertSame(AuditActorType::Administrator, $row->actorType());
        self::assertSame('store_setting', $row->resourceType());
        self::assertArrayHasKey(SettingKey::StoreDefaultTaxRate->value, $row->payload()['changes']);
        self::assertSame(
            22,
            $row->payload()['changes'][SettingKey::StoreDefaultTaxRate->value]['to'],
            'The audit row must record the value that was written, not the one submitted.',
        );
    }

    /**
     * Saving the form without changing anything must record nothing.
     *
     * A settings page posts every key on every save, so an audit row that ignored the diff would
     * bury the one real change under a stream of no-ops — and "somebody opened the settings page
     * and pressed save" is not a thing anybody needs to be able to prove.
     */
    public function testSavingTheSettingsFormWithoutChangingAnythingRecordsNothing(): void
    {
        $this->loginAsAdministrator();

        $crawler = $this->client->request('GET', '/yeni/admin/settings');
        $this->client->submit($crawler->selectButton('Save')->form());

        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_audit_log WHERE action = ?', [AuditAction::SettingsUpdated->value]),
        );
    }

    public function testASuccessfulSignInAndAFailedOneAreBothRecorded(): void
    {
        $administrator = $this->administrator('audit-admin@example.com');

        $crawler = $this->client->request('GET', '/yeni/admin/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'audit-admin@example.com',
            '_password' => 'not-the-password',
        ]));

        $failure = $this->lastRowFor(AuditAction::LoginFailed);
        self::assertNotNull($failure, 'A wrong password produced no audit row; a brute-force run would be invisible.');
        self::assertSame(AuditActorType::Anonymous, $failure->actorType(), 'A failed sign-in must not be credited to an account.');
        self::assertNull($failure->actorEmail());
        self::assertNotNull($failure->ipAddress(), 'The address is the whole point of a failed sign-in row.');
        self::assertSame('invalid_credentials', $failure->payload()['reason']);

        $this->client->restart();
        $crawler = $this->client->request('GET', '/yeni/admin/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'audit-admin@example.com',
            '_password' => self::PASSWORD,
        ]));

        $success = $this->lastRowFor(AuditAction::LoginSucceeded);
        self::assertNotNull($success);
        self::assertSame(AuditActorType::Administrator, $success->actorType());
        self::assertSame($administrator->getUserIdentifier(), $success->actorEmail());
    }

    /**
     * A failed sign-in row must not become a plaintext password corpus.
     *
     * The submitted password is the one value in this application that is simultaneously the most
     * sensitive thing it ever handles and the one an attacker supplies freely, so recording it
     * would hand every future attacker every password ever guessed.
     */
    public function testTheSubmittedPasswordIsNeverRecorded(): void
    {
        $this->administrator('audit-secret@example.com');

        $crawler = $this->client->request('GET', '/yeni/admin/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'audit-secret@example.com',
            '_password' => 'a-very-distinctive-wrong-password',
        ]));

        $encoded = $this->connection->fetchOne('SELECT payload FROM commerce_audit_log');
        self::assertIsString($encoded);
        self::assertStringNotContainsString('a-very-distinctive-wrong-password', $encoded);
    }

    public function testEveryRowOfOneRequestSharesItsRequestId(): void
    {
        $this->loginAsAdministrator();

        $crawler = $this->client->request('GET', '/yeni/admin/settings');
        $form = $crawler->selectButton('Save')->form();
        $form['store_settings[storeName]'] = 'Efe Otomotiv';
        $form['store_settings[defaultTaxRate]'] = '21';
        $this->client->submit($form);

        $requestId = $this->client->getResponse()->headers->get('X-Request-Id');
        self::assertIsString($requestId);
        self::assertNotSame('', $requestId);

        $stored = $this->connection->fetchOne('SELECT request_id FROM commerce_audit_log WHERE action = ? ORDER BY id DESC LIMIT 1', [AuditAction::SettingsUpdated->value]);
        self::assertSame($requestId, $stored, 'The audit row and the response must quote the same request id, or they cannot be correlated.');
    }

    /**
     * The request id is generated here, never taken from the caller.
     *
     * It is an audit correlation key: an attacker who chose it could collide two unrelated
     * requests, or make one look like part of another flow. So a *well-formed* id sent by the
     * caller is replaced just as surely as a malformed one.
     */
    public function testACallerSuppliedRequestIdIsNotHonoured(): void
    {
        foreach (["bad\r\nX-Injected: yes", str_repeat('a', 64), 'attacker-chosen-id'] as $supplied) {
            $this->client->request('GET', '/yeni/katalog', [], [], ['HTTP_X-Request-Id' => $supplied]);

            $header = (string) $this->client->getResponse()->headers->get('X-Request-Id');
            self::assertNotSame('', $header);
            self::assertStringNotContainsString("\r", $header, $supplied);
            self::assertStringNotContainsString('X-Injected', $header, $supplied);
            self::assertNotSame($supplied, $header, sprintf('The caller-supplied id "%s" was echoed back.', $supplied));
            self::assertMatchesRegularExpression('~^[a-f0-9]{32}$~', $header, 'A generated id is 32 lowercase hex characters.');
        }
    }

    public function testTheTrailSurvivesTheDeletionOfItsSubject(): void
    {
        // The property that makes the table worth having: no foreign key, so deactivating and
        // deleting a customer cannot erase the record that they were deactivated.
        $this->connection->insert('commerce_audit_log', [
            'action' => AuditAction::CustomerStatusChanged->value,
            'actor_type' => AuditActorType::Administrator->value,
            'actor_email' => 'audit-admin@example.com',
            'resource_type' => 'customer',
            'resource_id' => 'gone@example.com',
            'payload' => '{}',
            'ip_address' => '127.0.0.1',
            'request_id' => null,
            'occurred_at' => '2026-09-29 12:00:00',
        ]);

        $this->connection->executeStatement('DELETE FROM customer_user');

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_audit_log WHERE resource_id = ?', ['gone@example.com']));
    }

    /**
     * An audit failure must never be the reason a business action fails.
     *
     * `AuditLogger` catches everything and reports it. This drives a real save with a logger
     * whose entity manager refuses to persist, and asserts the settings were still written — the
     * alternative would be a store that cannot change its tax rate because its log is full.
     */
    public function testABrokenAuditLoggerDoesNotStopTheBusinessAction(): void
    {
        $refusing = $this->createStub(EntityManagerInterface::class);
        $refusing->method('persist')->willThrowException(new \RuntimeException('the audit table is unavailable'));

        $logger = new AuditLogger(
            $refusing,
            self::getContainer()->get(\App\Module\Audit\AuditContextResolver::class),
            self::getContainer()->get('clock'),
            new \Psr\Log\NullLogger(),
            $this->connection,
        );

        $settings = self::getContainer()->get(\App\Module\Settings\StoreConfiguration::class)->current();
        $settings->storeName = 'Written Despite The Audit Failure';

        // The real service, wired with a logger that cannot write.
        $withBrokenAudit = new \App\Module\Settings\StoreConfiguration(
            self::getContainer()->get(\App\Repository\Commerce\StoreSettingRepository::class),
            $this->entityManager,
            self::getContainer()->get(\Symfony\Component\Validator\Validator\ValidatorInterface::class),
            self::getContainer()->get(\App\Module\Integration\B2b\B2bProviderRegistry::class),
            $logger,
        );
        $withBrokenAudit->save($settings);

        self::assertSame(
            'Written Despite The Audit Failure',
            $this->storeSettingValue(SettingKey::StoreName->value),
        );
    }

    /**
     * Settings are stored as JSON, so the raw column is quoted. Reading it back through the JSON
     * decoder keeps the assertion about the value rather than about its serialisation.
     */
    private function storeSettingValue(string $key): ?string
    {
        $raw = $this->connection->fetchOne('SELECT value FROM store_setting WHERE setting_key = ?', [$key]);
        if (false === $raw || null === $raw) {
            return null;
        }
        $decoded = json_decode((string) $raw, true);

        return is_string($decoded) ? $decoded : (string) $raw;
    }

    public function testTheActionCatalogueCoversEverySubjectItNames(): void
    {
        foreach (AuditAction::cases() as $action) {
            self::assertNotSame('', $action->subjectHint(), $action->value);
            self::assertSame($action->value, mb_strtolower($action->value), 'Action values are identifiers and must not be reworded for readability.');
        }
    }

    private function lastRowFor(AuditAction $action): ?AuditLog
    {
        $this->entityManager->clear();
        $id = $this->connection->fetchOne('SELECT id FROM commerce_audit_log WHERE action = ? ORDER BY id DESC LIMIT 1', [$action->value]);
        if (false === $id || null === $id) {
            return null;
        }

        return $this->entityManager->find(AuditLog::class, (int) $id);
    }

    private function administrator(string $email): AdminUser
    {
        $administrator = new AdminUser($email);
        $administrator->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($administrator, self::PASSWORD));
        $this->entityManager->persist($administrator);
        $this->entityManager->flush();

        return $administrator;
    }

    private function loginAsAdministrator(): AdminUser
    {
        $administrator = $this->administrator('audit-operator@example.com');
        $this->client->loginUser($administrator, 'admin');

        return $administrator;
    }

    private function clear(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['commerce_audit_log', 'store_setting', 'customer_user', 'admin_user'] as $table) {
            $this->connection->executeStatement('DELETE FROM '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }
}
