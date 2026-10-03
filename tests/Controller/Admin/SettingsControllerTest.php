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

        $form = $crawler->selectButton('Ayarları kaydet')->form();
        $b2bEnabled = $form['store_settings[b2bEnabled]'];
        self::assertInstanceOf(ChoiceFormField::class, $b2bEnabled);
        $b2bEnabled->tick();
        $form['store_settings[b2bProvider]'] = 'efe';
        $form['store_settings[storeName]'] = 'Efe Otomotiv Updated';
        $this->client->submit($form);

        self::assertResponseRedirects('/yeni/admin/settings');

        $configuration = self::getContainer()->get(StoreConfiguration::class);
        self::assertTrue($configuration->isB2bEnabled());
        self::assertSame('efe', $configuration->b2bProvider());
        self::assertSame('Efe Otomotiv Updated', $configuration->storeName());
    }

    public function testInvalidTaxRateIsRejectedWithoutChangingStoredSettings(): void
    {
        $crawler = $this->client->request('GET', '/yeni/admin/settings');
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        $form['store_settings[defaultTaxRate]'] = '101';

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-errors', '100');
        self::assertSame(20, self::getContainer()->get(StoreConfiguration::class)->defaultTaxRate());
    }

    public function testInvalidCsrfTokenIsRejectedWithoutEnablingB2b(): void
    {
        $crawler = $this->client->request('GET', '/yeni/admin/settings');
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        $b2bEnabled = $form['store_settings[b2bEnabled]'];
        self::assertInstanceOf(ChoiceFormField::class, $b2bEnabled);
        $b2bEnabled->tick();
        $form['store_settings[_token]'] = 'invalid-token';

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse(self::getContainer()->get(StoreConfiguration::class)->isB2bEnabled());
    }

    public function testStorefrontColorsCanBeSavedAndAppearOnEveryStorefrontLayout(): void
    {
        $crawler = $this->client->request('GET', '/yeni/admin/settings');
        self::assertSelectorCount(9, 'fieldset input[type="color"]');
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        $colors = [
            'Notice' => '#010203', 'Navy' => '#102030', 'NavyLight' => '#203040',
            'Yellow' => '#abcdef', 'Body' => '#f0e0d0', 'Card' => '#fafafa',
            'Ink' => '#112233', 'Muted' => '#445566', 'Line' => '#778899',
        ];
        foreach ($colors as $name => $value) {
            $form['store_settings[storefront'.$name.']'] = $value;
        }
        $this->client->submit($form);
        self::assertResponseRedirects('/yeni/admin/settings');

        foreach (['/yeni/', '/yeni/katalog', '/yeni/markalar'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $css = $crawler->filter('style[data-storefront-colors]')->text();
            foreach (['notice' => '#010203', 'navy' => '#102030', 'navy-light' => '#203040', 'yellow' => '#abcdef', 'body' => '#f0e0d0', 'card' => '#fafafa', 'ink' => '#112233', 'muted' => '#445566', 'line' => '#778899'] as $name => $value) {
                self::assertStringContainsString('--storefront-'.$name.': '.$value.';', $css);
            }
        }
    }

    public function testInvalidStorefrontColorsAreRejectedWithoutPersistence(): void
    {
        foreach (['#fff', '#1234567', '#zzzzzz', 'red', '#123456; color:red', "#123456\n", ''] as $invalid) {
            $crawler = $this->client->request('GET', '/yeni/admin/settings');
            $form = $crawler->selectButton('Ayarları kaydet')->form();
            $form['store_settings[storefrontNavy]'] = $invalid;
            $this->client->submit($form);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('#092a53', self::getContainer()->get(StoreConfiguration::class)->current()->storefrontNavy);
        }
    }

    public function testAnonymousRequestsCannotChangeStorefrontColors(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/yeni/admin/settings', ['store_settings' => ['storefrontNavy' => '#123456']]);
        self::assertResponseRedirects('/yeni/admin/login');
        self::assertSame('#092a53', self::getContainer()->get(StoreConfiguration::class)->current()->storefrontNavy);
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
