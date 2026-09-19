<?php

namespace App\Tests\Controller\Storefront;

use App\Entity\Customer\AdminUser;
use App\Entity\Customer\CustomerAddress;
use App\Entity\Customer\CustomerUser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CustomerAccountTest extends WebTestCase
{
    private Connection $connection;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->clearCustomers();
    }

    protected function tearDown(): void
    {
        $this->clearCustomers();
        parent::tearDown();
    }

    public function testRegistrationPageIsAvailableToAnonymousCustomers(): void
    {
        $client = $this->client;
        $client->request('GET', '/yeni/kayit');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Hesap oluştur');
        self::assertSelectorExists('form[name="customer_registration"]');
    }

    public function testLoginPageIsAvailableToAnonymousCustomers(): void
    {
        $client = $this->client;
        $client->request('GET', '/yeni/giris');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Giriş yap');
        self::assertSelectorExists('input[name="_csrf_token"]');
        self::assertSelectorNotExists('#customer-email[autofocus]');
    }

    public function testAnonymousCustomerIsRedirectedFromAccountDashboard(): void
    {
        $client = $this->client;
        $client->request('GET', '/yeni/hesabim');

        self::assertResponseRedirects('/yeni/giris');
    }

    public function testCustomerCanRegisterAndPasswordIsHashed(): void
    {
        $client = $this->client;
        $crawler = $client->request('GET', '/yeni/kayit');
        $form = $crawler->selectButton('Hesap oluştur')->form([
            'customer_registration[firstName]' => 'Efe',
            'customer_registration[lastName]' => 'Yılmaz',
            'customer_registration[email]' => 'CUSTOMER@example.com',
            'customer_registration[plainPassword][first]' => 'VeryStrong!123',
            'customer_registration[plainPassword][second]' => 'VeryStrong!123',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/yeni/giris');
        $row = $this->connection->fetchAssociative('SELECT email, password FROM customer_user');
        self::assertIsArray($row);
        self::assertSame('customer@example.com', $row['email']);
        self::assertNotSame('VeryStrong!123', $row['password']);
    }

    public function testDuplicateNormalizedEmailRegistrationIsRejected(): void
    {
        $this->createCustomer('customer@example.com', 'VeryStrong!123');
        $client = $this->client;
        $crawler = $client->request('GET', '/yeni/kayit');
        $client->submit($crawler->selectButton('Hesap oluştur')->form([
            'customer_registration[firstName]' => 'Başka',
            'customer_registration[lastName]' => 'Müşteri',
            'customer_registration[email]' => ' CUSTOMER@EXAMPLE.COM ',
            'customer_registration[plainPassword][first]' => 'AnotherStrong!123',
            'customer_registration[plainPassword][second]' => 'AnotherStrong!123',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.account-form', 'zaten var');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM customer_user'));
    }

    public function testCustomerCanAuthenticateAndOpenDashboard(): void
    {
        $this->createCustomer('customer@example.com', 'VeryStrong!123');
        $client = $this->client;
        $crawler = $client->request('GET', '/yeni/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => 'CUSTOMER@example.com',
            '_password' => 'VeryStrong!123',
        ]));

        self::assertResponseRedirects('/yeni/hesabim');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Efe');
    }

    public function testAuthenticatedCustomerCanEditProfileAndChangePassword(): void
    {
        $customer = $this->createCustomer('customer@example.com', 'VeryStrong!123');
        $client = $this->client;
        $client->loginUser($customer, 'main');

        $crawler = $client->request('GET', '/yeni/hesabim/profil');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.account-form button.account-primary-button');
        $client->submit($crawler->selectButton('Profili kaydet')->form([
            'customer_profile[firstName]' => 'Efehan',
            'customer_profile[lastName]' => 'Yılmaz',
            'customer_profile[phone]' => '05320000000',
        ]));
        self::assertResponseRedirects('/yeni/hesabim');

        $crawler = $client->request('GET', '/yeni/hesabim/parola');
        self::assertSelectorExists('.account-form button.account-primary-button');
        $client->submit($crawler->selectButton('Parolayı değiştir')->form([
            'customer_password_change[currentPassword]' => 'VeryStrong!123',
            'customer_password_change[newPassword][first]' => 'EvenStronger!456',
            'customer_password_change[newPassword][second]' => 'EvenStronger!456',
        ]));
        self::assertResponseRedirects('/yeni/hesabim');

        $updatedCustomer = self::getContainer()->get(EntityManagerInterface::class)->find(CustomerUser::class, $customer->id());
        self::assertInstanceOf(CustomerUser::class, $updatedCustomer);
        self::assertSame('Efehan', $updatedCustomer->firstName());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($updatedCustomer, 'EvenStronger!456'));
    }

    public function testCustomerCanCreateAddressAndCannotAccessAnotherCustomersAddress(): void
    {
        $customerA = $this->createCustomer('a@example.com', 'VeryStrong!123');
        $customerB = $this->createCustomer('b@example.com', 'VeryStrong!123');
        $foreignAddress = new CustomerAddress($customerB);
        $foreignAddress->update('Ev', 'B Müşteri', '05320000001', 'Başka sokak 1', null, 'Seyhan', 'Adana', '01000', true);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($foreignAddress);
        $entityManager->flush();

        $client = $this->client;
        $client->loginUser($customerA, 'main');
        $crawler = $client->request('GET', '/yeni/hesabim/adresler/yeni');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.account-form button.account-primary-button');
        $client->submit($crawler->selectButton('Adresi kaydet')->form([
            'customer_address[label]' => 'İş',
            'customer_address[recipientName]' => 'A Müşteri',
            'customer_address[phone]' => '05320000002',
            'customer_address[addressLine1]' => 'Atatürk Caddesi 1',
            'customer_address[addressLine2]' => '',
            'customer_address[district]' => 'Çukurova',
            'customer_address[city]' => 'Adana',
            'customer_address[postalCode]' => '01170',
            'customer_address[defaultAddress]' => true,
        ]));
        self::assertResponseRedirects('/yeni/hesabim/adresler');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM customer_address WHERE customer_id = ?', [$customerA->id()]));

        $ownAddressId = (int) $this->connection->fetchOne('SELECT id FROM customer_address WHERE customer_id = ?', [$customerA->id()]);
        $crawler = $client->request('GET', '/yeni/hesabim/adresler/'.$ownAddressId.'/duzenle');
        $client->submit($crawler->selectButton('Adresi kaydet')->form([
            'customer_address[label]' => 'Merkez Ofis',
            'customer_address[recipientName]' => 'A Müşteri',
            'customer_address[phone]' => '05320000002',
            'customer_address[addressLine1]' => 'Atatürk Caddesi 2',
            'customer_address[addressLine2]' => '',
            'customer_address[district]' => 'Çukurova',
            'customer_address[city]' => 'Adana',
            'customer_address[postalCode]' => '01170',
            'customer_address[defaultAddress]' => true,
        ]));
        self::assertResponseRedirects('/yeni/hesabim/adresler');
        self::assertSame('Merkez Ofis', $this->connection->fetchOne('SELECT label FROM customer_address WHERE id = ?', [$ownAddressId]));

        $client->request('GET', '/yeni/hesabim/adresler/'.$foreignAddress->id().'/duzenle');
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/yeni/hesabim/adresler/'.$foreignAddress->id().'/duzenle', [
            'customer_address' => ['label' => 'Yetkisiz değişiklik'],
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Ev', $this->connection->fetchOne('SELECT label FROM customer_address WHERE id = ?', [$foreignAddress->id()]));

        $crawler = $client->request('GET', '/yeni/hesabim/adresler');
        self::assertSelectorExists('.account-primary-link');
        self::assertSelectorExists('.address-action-edit');
        self::assertSelectorExists('.address-action-delete');
        $client->submit($crawler->selectButton('Sil')->form());
        self::assertResponseRedirects('/yeni/hesabim/adresler');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM customer_address WHERE customer_id = ?', [$customerA->id()]));
    }

    public function testCustomerSessionDoesNotGrantAdministratorAccess(): void
    {
        $customer = $this->createCustomer('customer@example.com', 'VeryStrong!123');
        $this->client->loginUser($customer, 'main');

        $this->client->request('GET', '/yeni/admin');

        self::assertResponseRedirects('/yeni/admin/login');
        self::assertNotContains('ROLE_ADMIN', $customer->getRoles());
    }

    public function testAdministratorSessionDoesNotGrantCustomerAccountAccess(): void
    {
        $administrator = new AdminUser('customer-boundary-admin@example.com');
        $administrator->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($administrator, 'VeryStrong!123'));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($administrator);
        $entityManager->flush();
        $this->client->loginUser($administrator, 'admin');

        $this->client->request('GET', '/yeni/hesabim');

        self::assertResponseRedirects('/yeni/giris');
        self::assertNotContains('ROLE_CUSTOMER', $administrator->getRoles());
    }

    public function testInactiveCustomerCannotAuthenticate(): void
    {
        $customer = $this->createCustomer('inactive@example.com', 'VeryStrong!123');
        $customer->deactivate();
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $this->client->request('GET', '/yeni/giris');
        $this->client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => 'inactive@example.com',
            '_password' => 'VeryStrong!123',
        ]));

        self::assertResponseRedirects('/yeni/giris');
        $this->client->followRedirect();
        self::assertSelectorExists('[role="alert"]');
        $this->client->request('GET', '/yeni/hesabim');
        self::assertResponseRedirects('/yeni/giris');
    }

    public function testCustomerLogoutRejectsGetAndAcceptsCsrfProtectedPost(): void
    {
        $customer = $this->createCustomer('customer@example.com', 'VeryStrong!123');
        $this->client->loginUser($customer, 'main');

        $this->client->request('GET', '/yeni/cikis');
        self::assertResponseStatusCodeSame(405);

        $crawler = $this->client->request('GET', '/yeni/hesabim');
        $this->client->submit($crawler->selectButton('Çıkış yap')->form());
        self::assertResponseRedirects('/yeni/giris');

        $this->client->request('GET', '/yeni/hesabim');
        self::assertResponseRedirects('/yeni/giris');
    }

    private function createCustomer(string $email, string $password): CustomerUser
    {
        $customer = new CustomerUser($email, 'Efe', 'Yılmaz');
        $customer->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, $password));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($customer);
        $entityManager->flush();

        return $customer;
    }

    private function clearCustomers(): void
    {
        $this->connection->executeStatement('DELETE FROM customer_password_reset_token');
        $this->connection->executeStatement('DELETE FROM customer_address');
        $this->connection->executeStatement('DELETE FROM customer_user');
        $this->connection->executeStatement('DELETE FROM admin_user WHERE email = ?', ['customer-boundary-admin@example.com']);
    }
}
