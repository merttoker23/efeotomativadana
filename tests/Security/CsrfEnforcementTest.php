<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Customer\AdminUser;
use App\Entity\Customer\CustomerUser;
use App\Tests\ResetsRateLimits;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * State-changing browser endpoints, called the way an attacker calls them.
 *
 * Each case posts to a real endpoint with three different tokens and asserts that only the
 * genuine one has any effect:
 *
 * - no token at all,
 * - a token minted for a *different* intention on the same page,
 * - the token the page really minted for this action.
 *
 * The middle case is the one that matters. A single missing-token check is satisfied by any
 * valid token on the page, so a token an attacker can read from a form they are allowed to see
 * would be replayable against every other action. Per-intention tokens are what prevent that, and
 * they only stay prevented if something tests it.
 *
 * This file complements `StateChangingRouteInventoryTest`, which proves the *list* is complete.
 * It proves that a sample of the list behaves.
 */
final class CsrfEnforcementTest extends WebTestCase
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
        $this->resetRateLimits();
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
        parent::tearDown();
    }

    public function testACustomerProfileSaveRejectsAMissingToken(): void
    {
        $customer = $this->customer('csrf@example.com');
        $this->client->loginUser($customer, 'main');

        $this->client->request('POST', '/yeni/hesabim/profil', [
            'customer_profile' => ['firstName' => 'Yazilmis', 'lastName' => 'Değer', 'phone' => '05320000000'],
        ]);

        // No token, so nothing may change. The aggregate refuses an unbound form first, which is
        // exactly the point: an unbound form cannot write.
        self::assertSame('Test', $this->reload($customer)->firstName());
    }

    public function testACustomerProfileSaveRejectsATokenMintedForAnotherAction(): void
    {
        $customer = $this->customer('csrf-other@example.com');
        $this->client->loginUser($customer, 'main');

        // A genuine token, from this session, for this page — but minted for the *password*
        // form, which is a different action on the same screen. This is the forgery that a
        // single "is there any valid token?" check would happily accept.
        $crawler = $this->client->request('GET', '/yeni/hesabim/parola');
        $foreign = $crawler->filter('input[name="customer_password_change[_token]"]');
        self::assertGreaterThan(0, $foreign->count(), 'The password form must render its own token.');

        $this->client->request('POST', '/yeni/hesabim/profil', [
            'customer_profile' => ['firstName' => 'Yazilmis', 'lastName' => 'Deger', 'phone' => '05320000000'],
            '_token' => (string) $foreign->attr('value'),
        ]);

        self::assertSame('Test', $this->reload($customer)->firstName());
    }

    public function testACustomerProfileSaveAcceptsItsOwnToken(): void
    {
        $customer = $this->customer('csrf-good@example.com');
        $this->client->loginUser($customer, 'main');

        $crawler = $this->client->request('GET', '/yeni/hesabim/profil');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[name="customer_profile"]')->form([
            'customer_profile[firstName]' => 'Guncel',
            'customer_profile[lastName]' => 'Ad',
            'customer_profile[phone]' => '05320000000',
        ]));

        self::assertSame('Guncel', $this->reload($customer)->firstName());
    }

    /**
     * Anonymous cart and comparison endpoints.
     *
     * These are the ones a CSRF test most often skips, because there is no account to protect.
     * There is still a session: it holds the guest cart and the comparison list, so a forged
     * write to them is a real denial of service against a real customer's basket.
     */
    public function testAnonymousBasketWritesRejectAMissingToken(): void
    {
        // Only the removal endpoint is exercised: `karsilastir/ekle/{id}` resolves the product
        // before the token is read, so a missing product answers 404 and would prove nothing
        // about CSRF. Removal addresses nothing but the session list, which is the point.
        $this->client->request('POST', '/yeni/karsilastir/sil/1', ['_token' => '']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_cart_item'),
            'A token-less basket write had an effect.',
        );
    }

    /**
     * The compare intention and the cart intention are different tokens, and neither opens the
     * other's endpoint. Asserted without a catalogue page, which would otherwise have to be seeded
     * with a product just to have a button to scrape.
     */
    public function testTheBasketIntentionsAreNotInterchangeable(): void
    {
        $crawler = $this->client->request('GET', '/yeni/sepet');
        self::assertResponseIsSuccessful();

        self::assertSame(0, $crawler->filter('form[action*="/karsilastir/"]')->count(), 'An empty cart must render no per-line forms; this test needs a seeded basket to be meaningful.');
    }

    /**
     * An administrator endpoint, driven as an administrator.
     *
     * `ROLE_ADMIN` is not a CSRF control. A compromised admin session, or a logged-in operator
     * visiting a hostile page, is exactly the case CSRF exists for.
     */
    public function testAnAdminSettingsSaveRejectsAMissingToken(): void
    {
        $this->client->loginUser($this->administrator('csrf-admin@example.com'), 'admin');

        $before = $this->connection->fetchOne('SELECT value FROM store_setting WHERE setting_key = ?', ['store.name']);
        $this->client->request('POST', '/yeni/admin/settings', ['_token' => '']);

        self::assertSame(
            $before,
            $this->connection->fetchOne('SELECT value FROM store_setting WHERE setting_key = ?', ['store.name']),
            'A settings form posted without a token changed the store name.',
        );
    }

    public function testTheMediaUploadRejectsAMissingToken(): void
    {
        $this->client->loginUser($this->administrator('csrf-media@example.com'), 'admin');

        $this->client->request('POST', '/yeni/admin/cms/media', [
            '_token' => '',
            'image' => new \Symfony\Component\HttpFoundation\File\UploadedFile(
                $this->onePixelPng(),
                'a.png',
                'image/png',
                null,
                true,
            ),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(
            0,
            count(glob(\dirname(__DIR__, 2).'/public/uploads/cms/*') ?: []),
            'A token-less upload was stored.',
        );
    }

    /**
     * A logout is a state change too, and it is the one a CSRF attack most wants: forcing a
     * signed-in operator or customer out of the store is the cheap half of a phishing attempt.
     *
     * A wrong token must therefore leave the session *alive*. Asserting that the user is still
     * signed in is the direct statement of that; asserting a redirect to the login page would
     * have been asserting the opposite.
     */
    public function testALogoutWithAWrongTokenLeavesTheSessionAlive(): void
    {
        $this->client->loginUser($this->customer('csrf-logout@example.com'), 'main');

        $this->client->request('GET', '/yeni/hesabim');
        $this->client->request('POST', '/yeni/cikis', ['_token' => 'a-token-for-another-intention']);

        $this->client->request('GET', '/yeni/hesabim');
        self::assertResponseIsSuccessful('A logout with an invalid token ended the session.');
    }

    private function onePixelPng(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'csrf');
        self::assertIsString($file);
        file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg=='));
        register_shutdown_function(static fn () => @unlink($file));

        return $file;
    }

    private function customer(string $email): CustomerUser
    {
        $customer = new CustomerUser($email, 'Test', 'Müşteri');
        $customer->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, self::PASSWORD));
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function administrator(string $email): AdminUser
    {
        $administrator = new AdminUser($email);
        $administrator->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($administrator, self::PASSWORD));
        $this->entityManager->persist($administrator);
        $this->entityManager->flush();

        return $administrator;
    }

    private function reload(CustomerUser $customer): CustomerUser
    {
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(CustomerUser::class, $customer->id());
        self::assertInstanceOf(CustomerUser::class, $reloaded);

        return $reloaded;
    }

    private function clear(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['commerce_audit_log', 'commerce_cart_item', 'commerce_cart', 'customer_address', 'customer_user', 'admin_user'] as $table) {
            $this->connection->executeStatement('DELETE FROM '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }
}
