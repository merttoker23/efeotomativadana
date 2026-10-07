<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Customer\AdminUser;
use App\Tests\ResetsRateLimits;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AdminSecurityTest extends WebTestCase
{
    // The admin firewall now throttles, and its budget is shared across a suite run. Without
    // this a security test that exhausts it makes this class fail depending on test order.
    use ResetsRateLimits;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('DELETE FROM admin_user');
        $this->resetRateLimits();
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DELETE FROM admin_user');

        parent::tearDown();
    }

    public function testAnonymousAdministratorRequestRedirectsToLogin(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/login');
    }

    public function testAdministratorCanAuthenticateWithTheLoginForm(): void
    {
        $this->createAdministrator('admin@example.com', 'VeryStrong!123');

        $crawler = $this->client->request('GET', '/admin/login');
        self::assertSelectorNotExists('#username[autofocus]');
        $form = $crawler->selectButton('Giriş yap')->form([
            '_username' => 'ADMIN@example.com',
            '_password' => 'VeryStrong!123',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Genel bakış');
        self::assertSelectorExists('html[lang="tr"]');
        self::assertSelectorExists('.admin-sidebar a[aria-current="page"][href="/admin"]');
        self::assertSelectorExists('[data-testid="dashboard-products"]');
        self::assertSelectorExists('[data-admin-shell-target="toggle"][aria-expanded="false"]');
    }

    public function testNonAdminIdentityCannotAccessAdministratorRoutes(): void
    {
        $this->client->loginUser(new InMemoryUser(
            'viewer@example.com',
            'test-only-not-used-for-form-login',
            ['ROLE_USER'],
        ), 'admin');

        $this->client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    public function testLogoutRejectsGetAndAcceptsCsrfProtectedPost(): void
    {
        $administrator = $this->createAdministrator('admin@example.com', 'VeryStrong!123');
        $this->client->loginUser($administrator, 'admin');

        $this->client->request('GET', '/admin/logout');
        self::assertResponseStatusCodeSame(405);

        $crawler = $this->client->request('GET', '/admin');
        $form = $crawler->selectButton('Çıkış yap')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/login');

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');
    }

    private function createAdministrator(string $email, string $plainPassword): AdminUser
    {
        $administrator = new AdminUser($email);
        $administrator->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($administrator, $plainPassword),
        );

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($administrator);
        $entityManager->flush();

        return $administrator;
    }
}
