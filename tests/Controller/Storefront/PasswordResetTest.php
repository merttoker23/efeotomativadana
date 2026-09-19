<?php

namespace App\Tests\Controller\Storefront;

use App\Entity\Customer\CustomerUser;
use App\Entity\Customer\PasswordResetToken;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('DELETE FROM customer_password_reset_token');
        $this->connection->executeStatement('DELETE FROM customer_address');
        $this->connection->executeStatement('DELETE FROM customer_user');
    }

    public function testResetRequestStoresOnlyAHash(): void
    {
        $this->createCustomer();
        $crawler = $this->client->request('GET', '/yeni/parolami-unuttum');
        $this->client->submit($crawler->selectButton('Sıfırlama bağlantısı gönder')->form([
            'password_reset_request[email]' => 'CUSTOMER@example.com',
        ]));

        self::assertResponseRedirects('/yeni/parolami-unuttum');
        $hash = $this->connection->fetchOne('SELECT token_hash FROM customer_password_reset_token');
        self::assertIsString($hash);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        self::assertStringNotContainsString('customer@example.com', $hash);
    }

    public function testValidResetTokenChangesPasswordOnce(): void
    {
        $customer = $this->createCustomer();
        $rawToken = 'valid-reset-token';
        $this->persistToken($customer, $rawToken, new \DateTimeImmutable('+1 hour'));

        $crawler = $this->client->request('GET', '/yeni/parola-sifirla/'.$rawToken);
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Parolayı sıfırla')->form([
            'password_reset[newPassword][first]' => 'ResetStrong!456',
            'password_reset[newPassword][second]' => 'ResetStrong!456',
        ]));
        self::assertResponseRedirects('/yeni/giris');

        $updated = self::getContainer()->get(EntityManagerInterface::class)->find(CustomerUser::class, $customer->id());
        self::assertInstanceOf(CustomerUser::class, $updated);
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($updated, 'ResetStrong!456'));

        $this->client->request('GET', '/yeni/parola-sifirla/'.$rawToken);
        self::assertResponseStatusCodeSame(410);
    }

    public function testExpiredResetTokenIsRejected(): void
    {
        $customer = $this->createCustomer();
        $rawToken = 'expired-reset-token';
        $this->persistToken($customer, $rawToken, new \DateTimeImmutable('-1 minute'));

        $this->client->request('GET', '/yeni/parola-sifirla/'.$rawToken);

        self::assertResponseStatusCodeSame(410);
    }

    private function createCustomer(): CustomerUser
    {
        $customer = new CustomerUser('customer@example.com', 'Efe', 'Yılmaz');
        $customer->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, 'VeryStrong!123'));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($customer);
        $em->flush();
        return $customer;
    }

    private function persistToken(CustomerUser $customer, string $rawToken, \DateTimeImmutable $expiresAt): void
    {
        $token = new PasswordResetToken($customer, hash('sha256', $rawToken), $expiresAt, new \DateTimeImmutable());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($token);
        $em->flush();
    }
}
