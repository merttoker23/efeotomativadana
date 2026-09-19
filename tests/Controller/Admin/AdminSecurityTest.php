<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Customer\AdminUser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AdminSecurityTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('DELETE FROM admin_user');
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DELETE FROM admin_user');

        parent::tearDown();
    }

    public function testAnonymousAdministratorRequestRedirectsToLogin(): void
    {
        $this->client->request('GET', '/yeni/admin');

        self::assertResponseRedirects('/yeni/admin/login');
    }

    public function testAdministratorCanAuthenticateWithTheLoginForm(): void
    {
        $this->createAdministrator('admin@example.com', 'VeryStrong!123');

        $crawler = $this->client->request('GET', '/yeni/admin/login');
        self::assertSelectorNotExists('#username[autofocus]');
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => 'ADMIN@example.com',
            '_password' => 'VeryStrong!123',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/yeni/admin');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Administration');
    }

    public function testNonAdminIdentityCannotAccessAdministratorRoutes(): void
    {
        $this->client->loginUser(new InMemoryUser(
            'viewer@example.com',
            'test-only-not-used-for-form-login',
            ['ROLE_USER'],
        ), 'admin');

        $this->client->request('GET', '/yeni/admin');

        self::assertResponseStatusCodeSame(403);
    }

    public function testLogoutRejectsGetAndAcceptsCsrfProtectedPost(): void
    {
        $administrator = $this->createAdministrator('admin@example.com', 'VeryStrong!123');
        $this->client->loginUser($administrator, 'admin');

        $this->client->request('GET', '/yeni/admin/logout');
        self::assertResponseStatusCodeSame(405);

        $crawler = $this->client->request('GET', '/yeni/admin');
        $form = $crawler->selectButton('Sign out')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/yeni/admin/login');

        $this->client->request('GET', '/yeni/admin');
        self::assertResponseRedirects('/yeni/admin/login');
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
