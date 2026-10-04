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

    public function testSettingsOrderAndShippingAmountsInLira(): void
    {
        $crawler = $this->client->request('GET', '/yeni/admin/settings');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[name="store_settings[currency]"]');
        self::assertStringNotContainsString('TRY', $crawler->filter('main')->text());
        $formNode = $crawler->filter('form[name="store_settings"]');
        self::assertSame(['Mağaza', 'Özellikler', 'Sağlayıcılar', 'Kargo Ayarları', 'SEO', 'Google Analytics', 'Storefront Renkleri'], $formNode->filter('fieldset legend')->each(static fn ($node) => $node->text()));
        self::assertCount(1, $formNode->filter('button[type="submit"]'));
        self::assertSame(0, $formNode->filterXPath('.//button[@type="submit"]/following::fieldset')->count());
        self::assertSame(2, $formNode->filterXPath('.//fieldset[legend="SEO"]//input')->count());
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        self::assertSame('250.00', $form['store_settings[shippingFee]']->getValue());
        self::assertSame('1500.00', $form['store_settings[freeShippingThreshold]']->getValue());
        $form['store_settings[shippingFee]'] = '325.50';
        $form['store_settings[freeShippingThreshold]'] = '2000';
        $this->client->submit($form);
        self::assertResponseRedirects('/yeni/admin/settings');
        $configuration = self::getContainer()->get(StoreConfiguration::class);
        self::assertSame(32_550, $configuration->shippingFee());
        self::assertSame(200_000, $configuration->freeShippingThreshold());
        self::assertSame('TRY', $configuration->currency());
    }

    public function testNegativeShippingAmountIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/yeni/admin/settings');
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        $form['store_settings[shippingFee]'] = '-1';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(25_000, self::getContainer()->get(StoreConfiguration::class)->shippingFee());
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

    public function testAnalyticsSnippetIsNormalizedRenderedOnceAndRemovedOnNextRequest(): void
    {
        $crawler = $this->client->request('GET', '/yeni/katalog');
        self::assertCount(0, $crawler->filter('head script[src*="googletagmanager.com"]'));
        $crawler = $this->client->request('GET', '/yeni/admin/settings');
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        $form['store_settings[ga4MeasurementId]'] = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC1234567"></script><script>window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag("js", new Date()); gtag("config", "G-ABC1234567");</script>';
        $this->client->submit($form);
        self::assertResponseRedirects('/yeni/admin/settings');
        self::assertSame('G-ABC1234567', json_decode($this->connection->fetchOne('SELECT value FROM store_setting WHERE setting_key = ?', ['analytics.ga4_measurement_id']), true));
        self::assertSame('TRY', self::getContainer()->get(StoreConfiguration::class)->currency());

        foreach (['/yeni/', '/yeni/katalog', '/yeni/koleksiyonlar', '/yeni/giris', '/yeni/kayit', '/yeni/parolami-unuttum'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('head script[async][src="https://www.googletagmanager.com/gtag/js?id=G-ABC1234567"]'));
            self::assertCount(1, $crawler->filter('head script[data-storefront-analytics]'));
            $policy = $this->client->getResponse()->headers->get('Content-Security-Policy');
            self::assertStringContainsString('https://www.googletagmanager.com', $policy);
            self::assertStringContainsString('https://region1.google-analytics.com', $policy);
            self::assertStringNotContainsString('unsafe-eval', $policy);
        }
        $crawler = $this->client->request('GET', '/yeni/admin/settings');
        self::assertCount(0, $crawler->filter('script[src*="googletagmanager.com"]'));
        self::assertStringNotContainsString('googletagmanager.com', $this->client->getResponse()->headers->get('Content-Security-Policy'));
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        $form['store_settings[ga4MeasurementId]'] = '';
        $this->client->submit($form);
        self::assertResponseRedirects('/yeni/admin/settings');
        $crawler = $this->client->request('GET', '/yeni/katalog');
        self::assertCount(0, $crawler->filter('script[src*="googletagmanager.com"], script[data-storefront-analytics]'));
        self::assertStringNotContainsString('googletagmanager.com', $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testInvalidAnalyticsValuesAreRejectedAndStoredScriptsNeverRender(): void
    {
        foreach (['UA-1234567-1', 'G-ABC', 'G-ABC1234567<script>alert(1)</script>', '<script>alert(1)</script>', '<script src="https://evil.example/?id=G-ABC1234567"></script>', '<script src="https://www.googletagmanager.com/gtag/js?id=G-ABC1234567"></script><script>gtag("config", "G-XYZ1234567");</script>'] as $invalid) {
            $crawler = $this->client->request('GET', '/yeni/admin/settings');
            $form = $crawler->selectButton('Ayarları kaydet')->form();
            $form['store_settings[ga4MeasurementId]'] = $invalid;
            $this->client->submit($form);
            self::assertResponseStatusCodeSame(422);
            self::assertNull(self::getContainer()->get(StoreConfiguration::class)->current()->ga4MeasurementId);
        }
        $this->connection->update('store_setting', ['value' => json_encode('<script>alert(1)</script>')], ['setting_key' => 'analytics.ga4_measurement_id']);
        $crawler = $this->client->request('GET', '/yeni/katalog');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('script[src*="googletagmanager.com"], script[data-storefront-analytics]'));
        self::assertStringNotContainsString('<script>alert(1)</script>', $this->client->getResponse()->getContent());
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
