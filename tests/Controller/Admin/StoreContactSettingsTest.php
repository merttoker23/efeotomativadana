<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Customer\AdminUser;
use App\Module\Settings\SettingKey;
use App\Module\Settings\StoreConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Store country/city and optional phone settings. */
final class StoreContactSettingsTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->resetContactSettings();
        $this->client->loginUser($this->createAdministrator(), 'admin');
    }

    protected function tearDown(): void
    {
        $this->resetContactSettings();

        parent::tearDown();
    }

    public function testCountryAndCityUseLocalChoices(): void
    {
        $crawler = $this->client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[name="store_settings[district]"], [data-controller="store-address"]');
        self::assertSame(['Türkiye'], $crawler->filter('select[name="store_settings[country]"] option')->each(static fn ($node) => $node->attr('value')));
        $cities = $crawler->filter('select[name="store_settings[city]"] option')->each(static fn ($node) => $node->attr('value'));
        self::assertCount(82, $cities);
        self::assertContains('Adana', $cities);
        self::assertContains('Şanlıurfa', $cities);
    }

    public function testCountryCityAndPhoneSaveWithoutChangingLegacyDistrict(): void
    {
        $this->storeSetting('store.district', 'Seyhan');
        $form = $this->client->request('GET', '/admin/settings')->selectButton('Ayarları kaydet')->form();
        $form['store_settings[country]'] = 'Türkiye';
        $form['store_settings[city]'] = 'Adana';
        $form['store_settings[phone]'] = '0322 123 45 67';
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/settings');
        self::assertSame('Türkiye', $this->storedSetting('store.country'));
        self::assertSame('Adana', $this->storedSetting('store.city'));
        self::assertSame('Seyhan', $this->storedSetting('store.district'));
        self::assertSame('+90 322 123 45 67', $this->storedSetting('store.phone'));
        $form = $this->client->request('GET', '/admin/settings')->selectButton('Ayarları kaydet')->form();
        self::assertSame('Türkiye', $form['store_settings[country]']->getValue());
        self::assertSame('Adana', $form['store_settings[city]']->getValue());
    }

    public function testUnknownCountryOrProvinceIsRefused(): void
    {
        foreach (['country' => 'Unknown', 'city' => 'Adaa'] as $field => $value) {
            $form = $this->client->request('GET', '/admin/settings')->selectButton('Ayarları kaydet')->form();
            $values = $form->getPhpValues();
            $values['store_settings'][$field] = $value;
            $this->client->request('POST', '/admin/settings', $values);
            self::assertResponseStatusCodeSame(422);
            self::assertNull(self::getContainer()->get(StoreConfiguration::class)->city());
        }
    }

    public function testAPhoneThatIsNotANumberIsRefusedAndTheStoredOneIsKept(): void
    {
        $this->storeSetting('store.phone', '+90 322 123 45 67');

        foreach (['444', '322 123 45', 'tel:+903221234567', 'abc'] as $invalid) {
            $crawler = $this->client->request('GET', '/admin/settings');
            $form = $crawler->selectButton('Ayarları kaydet')->form();
            $form['store_settings[phone]'] = $invalid;
            $this->client->submit($form);

            self::assertResponseStatusCodeSame(422, sprintf('"%s" should be refused.', $invalid));
            self::assertSame('+90 322 123 45 67', $this->storedSetting('store.phone'));
        }
    }

    public function testTheContactFieldsAreOptionalAndClearingThemIsAValidSave(): void
    {
        $this->storeSetting('store.phone', '+90 322 123 45 67');
        $this->storeSetting('store.city', 'Adana');
        $this->storeSetting('store.district', 'Seyhan');

        $crawler = $this->client->request('GET', '/admin/settings');
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        $form['store_settings[phone]'] = '';
        $form['store_settings[city]'] = '';
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/settings');
        $configuration = self::getContainer()->get(StoreConfiguration::class);
        self::assertNull($configuration->phone());
        self::assertNull($configuration->city());
        self::assertSame('Seyhan', $this->storedSetting('store.district'));
        self::assertNull($this->storedSetting('store.phone'));
    }

    private function createAdministrator(): AdminUser
    {
        $administrator = new AdminUser('store-contact-admin@example.com');
        $administrator->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($administrator, 'VeryStrong!123'),
        );
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $manager->persist($administrator);
        $manager->flush();

        return $administrator;
    }

    private function storedSetting(string $key): mixed
    {
        $value = $this->connection->fetchOne('SELECT value FROM store_setting WHERE setting_key = ?', [$key]);

        return null === $value ? null : json_decode((string) $value, true);
    }

    private function storeSetting(string $key, ?string $value): void
    {
        $this->connection->update('store_setting', ['value' => json_encode($value)], ['setting_key' => $key]);
    }

    /**
     * Only this test's own rows are removed. The three keys this test writes are recreated from
     * their declared default, exactly as a fresh install would hold them, so no test is left with
     * a setting this one invented.
     */
    private function resetContactSettings(): void
    {
        $this->connection->delete('admin_user');
        foreach ([SettingKey::StorePhone, SettingKey::StoreCountry, SettingKey::StoreCity, SettingKey::StoreDistrict] as $key) {
            $this->connection->executeStatement(
                'INSERT INTO store_setting (setting_key, value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [$key->value, json_encode($key->defaultValue()), (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            );
        }
    }
}
