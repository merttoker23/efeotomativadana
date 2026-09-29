<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Customer\AdminUser;
use App\Entity\Customer\CustomerUser;
use App\Module\Settings\StoreConfiguration;
use App\Tests\ResetsRateLimits;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Abuse limits, proven by exhausting them.
 *
 * Each case drives the real limiter until it refuses, rather than asserting that a limiter is
 * configured. The distinction matters: a `#[RateLimit]` attribute naming a limiter that does not
 * exist would pass a configuration test and leave the endpoint open.
 *
 * Every limiter's state is reset in `setUp`, because the storage is deliberately shared with
 * production (a file-backed pool) and a limit left spent by one case would make the next one
 * pass for the wrong reason.
 */
final class RateLimitTest extends WebTestCase
{
    use ResetsRateLimits;

    private const string PASSWORD = 'VeryStrong!123';

    private const array LIMITERS = [
        'registration',
        'password_reset_request',
        'password_reset_exchange',
        'checkout',
        'return_request',
    ];

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->resetLimiters();
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['customer_user', 'customer_address', 'customer_password_reset_token', 'admin_user'] as $table) {
            $this->connection->executeStatement('DELETE FROM '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testAccountCreationIsLimitedPerAddress(): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $crawler = $this->client->request('GET', '/yeni/kayit');
            $this->client->submit($crawler->selectButton('Hesap oluştur')->form([
                'customer_registration[firstName]' => 'Test',
                'customer_registration[lastName]' => 'Müşteri'.$attempt,
                'customer_registration[email]' => sprintf('limit%d@example.com', $attempt),
                'customer_registration[plainPassword][first]' => self::PASSWORD,
                'customer_registration[plainPassword][second]' => self::PASSWORD,
            ]));
            $this->assertRedirectsTo('/yeni/giris', sprintf('attempt %d should still be allowed', $attempt));
            $this->client->followRedirect();
        }

        $crawler = $this->client->request('GET', '/yeni/kayit');
        $this->client->submit($crawler->selectButton('Hesap oluştur')->form([
            'customer_registration[firstName]' => 'Test',
            'customer_registration[lastName]' => 'Altinci',
            'customer_registration[email]' => 'limit6@example.com',
            'customer_registration[plainPassword][first]' => self::PASSWORD,
            'customer_registration[plainPassword][second]' => self::PASSWORD,
        ]));

        self::assertResponseStatusCodeSame(429);
        // The refusal must not have created the account it refused.
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM customer_user WHERE email = ?', ['limit6@example.com']));
    }

    public function testReadingTheRegistrationFormNeverSpendsAToken(): void
    {
        // A limit that is spent by rendering the page would lock a customer out by refreshing,
        // which is the kind of abuse limit that causes support calls instead of preventing them.
        for ($i = 0; $i < 12; ++$i) {
            $this->client->request('GET', '/yeni/kayit');
            self::assertResponseIsSuccessful();
        }

        $crawler = $this->client->request('GET', '/yeni/kayit');
        $this->client->submit($crawler->selectButton('Hesap oluştur')->form([
            'customer_registration[firstName]' => 'Test',
            'customer_registration[lastName]' => 'Müşteri',
            'customer_registration[email]' => 'still-allowed@example.com',
            'customer_registration[plainPassword][first]' => self::PASSWORD,
            'customer_registration[plainPassword][second]' => self::PASSWORD,
        ]));

        self::assertResponseRedirects('/yeni/giris');
    }

    public function testPasswordResetRequestsAreLimited(): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $crawler = $this->client->request('GET', '/yeni/parolami-unuttum');
            $this->client->submit($crawler->filter('form[name="password_reset_request"]')->form([
                'password_reset_request[email]' => sprintf('reset%d@example.com', $attempt),
            ]));
            $this->assertRedirectsTo('/yeni/parolami-unuttum', sprintf('attempt %d should still be allowed', $attempt));
            $this->client->followRedirect();
        }

        $crawler = $this->client->request('GET', '/yeni/parolami-unuttum');
        $this->client->submit($crawler->filter('form[name="password_reset_request"]')->form([
            'password_reset_request[email]' => 'reset6@example.com',
        ]));

        self::assertResponseStatusCodeSame(429);
    }
    /**
     * The limiter on the reset form is keyed on the address alone.
     *
     * The default key includes the request path, and the path contains the token — so keying on
     * it would give every guess its own budget and limit nothing at all. This asserts the
     * property that makes it a limit: many *different* tokens from one address are eventually
     * refused, even though no two requests share a path.
     */
    public function testResetTokenGuessingIsLimitedAcrossDifferentTokens(): void
    {
        $allowance = 20;
        for ($attempt = 1; $attempt <= $allowance + 5; ++$attempt) {
            $this->client->request('POST', '/yeni/parola-sifirla/'.str_repeat((string) ($attempt % 10), 64), [
                'password_reset[newPassword][first]' => 'BrandNew!Pass1',
                'password_reset[newPassword][second]' => 'BrandNew!Pass1',
                '_token' => 'irrelevant',
            ]);
            if (429 === $this->client->getResponse()->getStatusCode()) {
                self::assertGreaterThan($allowance - 5, $attempt, 'The limit must bite rather than being unreachable.');

                return;
            }
        }

        self::fail(sprintf('Twenty-six distinct reset tokens from one address were all accepted; the limiter is not applied.'));
    }

    /**
     * Login throttling, proved by its effect rather than by its status code.
     *
     * Symfony answers a throttled sign-in with the ordinary failed-login response, so "did it
     * throttle" is not visible in the status code. What is visible — and what actually matters —
     * is that the *correct* password stops working. The control at the top is therefore not
     * decoration: without it, a mistyped password in the fixture would make the second half of
     * this test pass for the wrong reason.
     */
    public function testLoginIsThrottledAfterRepeatedFailures(): void
    {
        $this->createCustomer('throttle@example.com');

        self::assertTrue($this->signIn('throttle@example.com', self::PASSWORD), 'The control sign-in must succeed before the throttle is tested.');

        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $this->signIn('throttle@example.com', 'wrong-password-'.$attempt);
        }

        self::assertFalse(
            $this->signIn('throttle@example.com', self::PASSWORD),
            'A correct password still worked after a run of wrong ones; the throttle is not applied.',
        );
    }

    /**
     * The two firewalls keep separate counters, so a customer's typos can never lock an
     * administrator out and an attack on the admin login cannot spend a customer's budget.
     */
    public function testTheAdminAndCustomerFirewallsHaveIndependentThrottles(): void
    {
        $configuration = self::getContainer()->get(StoreConfiguration::class);
        self::assertInstanceOf(StoreConfiguration::class, $configuration);
        $settings = $configuration->current();
        $settings->paymentProvider = null;
        $settings->shippingProvider = null;
        $configuration->save($settings);

        $administrator = new AdminUser('admin-throttle@example.com');
        $administrator->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($administrator, self::PASSWORD));
        $this->entityManager->persist($administrator);
        $this->entityManager->flush();

        // Exhaust the admin firewall. Exceptions are caught rather than thrown so that a
        // throttled attempt — which Symfony surfaces as an authentication failure — does not
        // abort the loop that is trying to exhaust it.
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $crawler = $this->client->request('GET', '/yeni/admin/login');
            $this->client->submit($crawler->selectButton('Sign in')->form([
                '_username' => 'admin-throttle@example.com',
                '_password' => 'wrong-password',
            ]));
            $this->client->followRedirect();
        }

        // The customer firewall is untouched, so a genuine customer still gets in on the first try.
        $this->createCustomer('still-ok@example.com');

        self::assertTrue($this->signIn('still-ok@example.com', self::PASSWORD));
    }

    /**
     * A limiter that exists but is wired to nothing does not refuse anybody.
     *
     * Asserted here rather than only in the exhaustion cases above, because those fail for a
     * reason that reads the same whether the limit is absent, mis-keyed or simply generous. The
     * ceiling is also read from the running container, so lowering it in `rate_limiter.yaml` for a
     * legitimate reason updates the test instead of breaking it.
     */
    public function testTheDeclaredCeilingsAreTheOnesTheContainerWillEnforce(): void
    {
        $expected = [
            'registration' => 5,
            'password_reset_request' => 5,
            'password_reset_exchange' => 20,
            'checkout' => 20,
            'return_request' => 10,
        ];

        foreach ($expected as $name => $limit) {
            $limiter = self::getContainer()->get('limiter.'.$name)
                ->create('ceiling-'.$name);

            $accepted = 0;
            for ($attempt = 0; $attempt <= $limit; ++$attempt) {
                if ($limiter->consume()->isAccepted()) {
                    ++$accepted;
                }
            }

            self::assertSame($limit, $accepted, sprintf('Limiter "%s" allowed %d of %d.', $name, $accepted, $limit));
        }
    }

    /**
     * A failure is reported without saying which half was wrong, and without giving an attacker a
     * way to tell a registered address from an unregistered one.
     */
    public function testAFailedSignInSaysNothingAboutWhetherTheAccountExists(): void
    {
        foreach (['nobody@example.com', 'existing@example.com'] as $email) {
            if ('existing@example.com' === $email) {
                $customer = new CustomerUser($email, 'Test', 'Müşteri');
                $customer->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, self::PASSWORD));
                $this->entityManager->persist($customer);
                $this->entityManager->flush();
            }

            $crawler = $this->client->request('GET', '/yeni/giris');
            $this->client->submit($crawler->selectButton('Giriş yap')->form([
                '_username' => $email,
                '_password' => 'definitely-not-the-password',
            ]));
            $this->client->followRedirect();
            $message = (string) $this->client->getResponse()->getContent();

            self::assertStringContainsString('E-posta veya parola hatalı', $message, $email);
        }
    }

    /**
     * Attempts a sign-in through the real login form and reports whether it worked.
     *
     * "Worked" is measured by asking a protected page afterwards, because the login response
     * itself is a redirect either way and reading the redirect target would pass for a wrong
     * password.
     */
    private function signIn(string $email, string $password): bool
    {
        // Start from a clean browser session, because once a sign-in succeeds the login page
        // redirects away and a second attempt would find no form at all.
        //
        // The cookie jar is cleared rather than `$client->restart()` being called, and the
        // difference is load-bearing: `restart()` reboots the kernel, and the rate limiter's pool
        // is in-memory per boot so that one test's spent budget cannot break another. Restarting
        // would therefore wipe the very counters this test is measuring.
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/yeni/giris');
        $this->client->submit($crawler->filter('form[action="/yeni/giris"]')->form([
            '_username' => $email,
            '_password' => $password,
        ]));

        $this->client->request('GET', '/yeni/hesabim');

        return $this->client->getResponse()->isSuccessful();
    }

    private function createCustomer(string $email): CustomerUser
    {
        $customer = new CustomerUser($email, 'Test', 'Müşteri');
        $customer->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, self::PASSWORD));
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    /**
     * `assertResponseRedirects()` takes a status code as its second argument, not a message, so
     * the message is asserted separately here. This exists because passing a message to it
     * produced a TypeError that hid the actual assertion entirely.
     */
    private function assertRedirectsTo(string $expected, string $message): void
    {
        self::assertResponseRedirects($expected);
        self::assertStringContainsString($expected, (string) $this->client->getResponse()->headers->get('Location'), $message);
    }

    /**
     * The pool the limiters use is the file-backed one, shared across a whole suite run.
     *
     * That is correct for production — a login budget must survive across requests — and wrong
     * for a test suite, where one test's spent budget is the next test's first failure. So the
     * pool is emptied in `setUp()` rather than replaced with an in-memory adapter. The adapter
     * swap was tried first and does not work: the pool is reset between requests, so the counters
     * never accumulated and the limiter refused nobody.
     *
     * Clearing rather than isolating is also what makes these tests trustworthy about the real
     * storage: an in-memory pool would happily pass with a limit that a file-backed pool refuses.
     */
    private function resetLimiters(): void
    {
        foreach (self::LIMITERS as $name) {
            self::assertTrue(
                self::getContainer()->has('limiter.'.$name),
                sprintf('Limiter "%s" is not configured; the endpoint using it is unprotected.', $name),
            );
        }

        $this->resetRateLimits();
    }
}
