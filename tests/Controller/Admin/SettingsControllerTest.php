<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Customer\AdminUser;
use App\Module\Settings\SettingKey;
use App\Module\Settings\StoreConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

final class SettingsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->resetDatabaseState();
        $this->client->loginUser($this->createAdministrator(), 'admin');
    }

    protected function tearDown(): void
    {
        $this->resetDatabaseState();

        parent::tearDown();
    }

    public function testAdministratorCanEnableB2bAndPersistStoreSettings(): void
    {
        $crawler = $this->client->request('GET', '/yeni/admin/settings');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[type="checkbox"][name="store_settings[b2bEnabled]"]'));

        $form = $crawler->selectButton('Save settings')->form();
        $b2bEnabled = $form['store_settings[b2bEnabled]'];
        self::assertInstanceOf(ChoiceFormField::class, $b2bEnabled);
        $b2bEnabled->tick();
        $form['store_settings[b2bProvider]'] = 'example_provider';
        $form['store_settings[storeName]'] = 'Efe Otomotiv Updated';
        $this->client->submit($form);

        self::assertResponseRedirects('/yeni/admin/settings');

        $configuration = self::getContainer()->get(StoreConfiguration::class);
        self::assertTrue($configuration->isB2bEnabled());
        self::assertSame('example_provider', $configuration->b2bProvider());
        self::assertSame('Efe Otomotiv Updated', $configuration->storeName());
    }

    public function testInvalidTaxRateIsRejectedWithoutChangingStoredSettings(): void
    {
        $crawler = $this->client->request('GET', '/yeni/admin/settings');
        $form = $crawler->selectButton('Save settings')->form();
        $form['store_settings[defaultTaxRate]'] = '101';

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-errors', '100');
        self::assertSame(20, self::getContainer()->get(StoreConfiguration::class)->defaultTaxRate());
    }

    public function testInvalidCsrfTokenIsRejectedWithoutEnablingB2b(): void
    {
        $crawler = $this->client->request('GET', '/yeni/admin/settings');
        $form = $crawler->selectButton('Save settings')->form();
        $b2bEnabled = $form['store_settings[b2bEnabled]'];
        self::assertInstanceOf(ChoiceFormField::class, $b2bEnabled);
        $b2bEnabled->tick();
        $form['store_settings[_token]'] = 'invalid-token';

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse(self::getContainer()->get(StoreConfiguration::class)->isB2bEnabled());
    }

    private function createAdministrator(): AdminUser
    {
        $administrator = new AdminUser('settings-admin@example.com');
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

    private function resetDatabaseState(): void
    {
        $this->connection->executeStatement('DELETE FROM admin_user');
        $this->connection->executeStatement('DELETE FROM store_setting');

        foreach (SettingKey::cases() as $key) {
            $value = json_encode($key->defaultValue(), JSON_THROW_ON_ERROR);
            $this->connection->insert('store_setting', [
                'setting_key' => $key->value,
                'value' => $value,
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }
    }
}
