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

/**
 * Mağaza Ayarları > Mağaza bölümündeki iletişim alanları: telefon, il ve ilçe.
 *
 * Doğrulama iki yerden gelir ve ikisi de sınanır. Tarayıcı tarafı seçim kutusunu daraltır;
 * asıl karar sunucuya aittir, çünkü gönderilen değer istendiği gibi değiştirilebilir. Buradaki
 * her reddetme senaryosu, tarayıcının gönderdiği geçersiz bir değeri taklit eder.
 */
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

    public function testTheContactFieldsAreSelectsOverTheCatalogRatherThanFreeText(): void
    {
        $crawler = $this->client->request('GET', '/yeni/admin/settings');
        self::assertResponseIsSuccessful();

        $city = $crawler->filter('select[name="store_settings[city]"]');
        $district = $crawler->filter('select[name="store_settings[district]"]');
        self::assertCount(1, $city);
        self::assertCount(1, $district);
        // No free-text fallback: the province and the district are selects, not inputs.
        self::assertCount(0, $crawler->filter('input[name="store_settings[city]"], input[name="store_settings[district]"]'));
        self::assertSelectorExists('[name="store_settings[phone]"]');

        $cities = $city->filter('option')->each(static fn ($node) => $node->attr('value'));
        self::assertCount(82, $cities, '81 provinces plus the empty choice.');
        self::assertContains('Adana', $cities);
        self::assertSame('Adana', $city->filter('option[value="Adana"]')->text());
        self::assertSame('Seyhan', $district->filter('option[value="Seyhan"]')->text());
        self::assertContains('Şanlıurfa', $cities);
        self::assertContains('Seyhan', $district->filter('option')->each(static fn ($node) => $node->attr('value')));
        self::assertContains('Şehitkamil', $district->filter('option')->each(static fn ($node) => $node->attr('value')));

        // The catalog the dependent district list narrows by travels with the page, so changing a
        // province never costs a request.
        $catalog = json_decode((string) $crawler->filter('[data-controller="store-address"]')->attr('data-store-address-districts-value'), true);
        self::assertIsArray($catalog);
        self::assertContains('Seyhan', $catalog['Adana']);
        self::assertNotContains('Şehitkamil', $catalog['Adana']);
    }

    public function testPhoneCityAndDistrictAreSavedTogether(): void
    {
        $crawler = $this->client->request('GET', '/yeni/admin/settings');
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        $form['store_settings[phone]'] = '0322 123 45 67';
        $form['store_settings[city]'] = 'Adana';
        $form['store_settings[district]'] = 'Seyhan';
        $this->client->submit($form);

        self::assertResponseRedirects('/yeni/admin/settings');

        $configuration = self::getContainer()->get(StoreConfiguration::class);
        self::assertSame('+90 322 123 45 67', $configuration->phone());
        self::assertSame('Adana', $configuration->city());
        self::assertSame('Seyhan', $configuration->district());
        // The canonical form is what is stored, not what was typed.
        self::assertSame('+90 322 123 45 67', $this->storedSetting('store.phone'));

        $form = $this->client->request('GET', '/yeni/admin/settings')->selectButton('Ayarları kaydet')->form();
        self::assertSame('+90 322 123 45 67', $form['store_settings[phone]']->getValue());
        self::assertSame('Adana', $form['store_settings[city]']->getValue());
        self::assertSame('Seyhan', $form['store_settings[district]']->getValue());
    }

    public function testADistrictThatBelongsToAnotherProvinceIsRefused(): void
    {
        $crawler = $this->client->request('GET', '/yeni/admin/settings');
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        $form['store_settings[city]'] = 'Adana';
        $form['store_settings[district]'] = 'Şehitkamil';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $configuration = self::getContainer()->get(StoreConfiguration::class);
        self::assertNull($configuration->city());
        self::assertNull($configuration->district());
    }

    public function testADistrictWithoutAProvinceIsRefused(): void
    {
        $crawler = $this->client->request('GET', '/yeni/admin/settings');
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        $form['store_settings[district]'] = 'Seyhan';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertNull(self::getContainer()->get(StoreConfiguration::class)->district());
    }

    public function testAProvinceOrDistrictThatDoesNotExistIsRefused(): void
    {
        // Posted raw rather than through the form: the DOM crawler refuses to set a choice the
        // page does not offer, which is exactly what a tampered request would send.
        foreach ([['city', 'Adaa', 'Seyhan'], ['district', 'Adana', 'Bilinmeyen İlçe']] as [$field, $city, $district]) {
            $this->postTamperedField($field, 'district' === $field ? $district : $city, $city, $district);

            self::assertResponseStatusCodeSame(422, sprintf('"%s" should be refused.', $field));
            self::assertNull(self::getContainer()->get(StoreConfiguration::class)->city());
            self::assertNull(self::getContainer()->get(StoreConfiguration::class)->district());
        }
    }

    public function testAPhoneThatIsNotANumberIsRefusedAndTheStoredOneIsKept(): void
    {
        $this->storeSetting('store.phone', '+90 322 123 45 67');

        foreach (['444', '322 123 45', 'tel:+903221234567', 'abc'] as $invalid) {
            $crawler = $this->client->request('GET', '/yeni/admin/settings');
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

        $crawler = $this->client->request('GET', '/yeni/admin/settings');
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        $form['store_settings[phone]'] = '';
        $form['store_settings[city]'] = '';
        $form['store_settings[district]'] = '';
        $this->client->submit($form);

        self::assertResponseRedirects('/yeni/admin/settings');
        $configuration = self::getContainer()->get(StoreConfiguration::class);
        self::assertNull($configuration->phone());
        self::assertNull($configuration->city());
        self::assertNull($configuration->district());
        self::assertNull($this->storedSetting('store.phone'));
    }

    /**
 * Formu geçerli biçimde doldurup tek bir alanı elle değiştirerek gönderir.
 *
 * Bu, tarayıcının seçim kutusunda sunmadığı bir değeri gönderen bir isteği taklit eder;
 * seçim kutusunun kendisi böyle bir gönderimi engellediği için engellemesi de bir doğrulama
 * sayılmaz. Geri kalan alanlar formdan alınır, böylece reddedilen tek şeyin bu alan olduğu
 * görülür.
 */
private function postTamperedField(string $field, string $value, string $city, string $district): void
{
    $form = $this->client->request('GET', '/yeni/admin/settings')->selectButton('Ayarları kaydet')->form();
    $values = $form->getPhpValues();
    $values['store_settings']['city'] = $city;
    $values['store_settings']['district'] = $district;
    $values['store_settings'][$field] = $value;
    $values['store_settings']['_token'] = (string) $form['store_settings[_token]']->getValue();

    $this->client->request('POST', '/yeni/admin/settings', $values);
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
        foreach ([SettingKey::StorePhone, SettingKey::StoreCity, SettingKey::StoreDistrict] as $key) {
            $this->connection->executeStatement(
                'INSERT INTO store_setting (setting_key, value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [$key->value, json_encode($key->defaultValue()), (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            );
        }
    }
}
