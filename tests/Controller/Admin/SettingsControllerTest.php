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
        $form['store_settings[contactEmail]'] = 'contact@example.com';
        $this->client->submit($form);

        self::assertResponseRedirects('/yeni/admin/settings');

        $configuration = self::getContainer()->get(StoreConfiguration::class);
        self::assertTrue($configuration->isB2bEnabled());
        self::assertSame('efe', $configuration->b2bProvider());
        self::assertSame('Efe Otomotiv Updated', $configuration->storeName());
        self::assertSame('contact@example.com', $configuration->contactEmail());
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
        self::assertSame(['Mağaza', 'Özellikler', 'Google Analytics', 'Storefront Renkleri'], $formNode->filter('fieldset legend')->each(static fn ($node) => $node->text()));
        self::assertCount(1, $formNode->filter('button[type="submit"]'));
        self::assertSame(0, $formNode->filterXPath('.//button[@type="submit"]/following::fieldset')->count());
        self::assertCount(0, $formNode->filter('[name*="shipping"], [name*="seo"], [name*="paymentProvider"]'));
        $crawler = $this->client->request('GET', '/yeni/admin/settings/shipping');
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        self::assertSame('250.00', $form['shipping_settings[shippingFee]']->getValue());
        self::assertSame('1500.00', $form['shipping_settings[freeShippingThreshold]']->getValue());
        $form['shipping_settings[shippingFee]'] = '325.50';
        $form['shipping_settings[freeShippingThreshold]'] = '2000';
        $this->client->submit($form);
        self::assertResponseRedirects('/yeni/admin/settings/shipping');
        $configuration = self::getContainer()->get(StoreConfiguration::class);
        self::assertSame(32_550, $configuration->shippingFee());
        self::assertSame(200_000, $configuration->freeShippingThreshold());
        self::assertSame('TRY', $configuration->currency());
    }

    public function testNegativeShippingAmountIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/yeni/admin/settings/shipping');
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        $form['shipping_settings[shippingFee]'] = '-1';
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

    public function testSeoAndGeneralFormsOnlyWriteTheirOwnSettings(): void
    {
        $crawler = $this->client->request('GET', '/yeni/admin/settings/seo');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        $form['seo_settings[seoIndexingEnabled]']->untick();
        $form['seo_settings[seoDefaultDescription]'] = 'Mağazanın SEO açıklaması';
        $this->client->submit($form);
        self::assertResponseRedirects('/yeni/admin/settings/seo');
        self::assertSame(25_000, self::getContainer()->get(StoreConfiguration::class)->shippingFee());

        $crawler = $this->client->request('GET', '/yeni/admin/settings');
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        // A different administrator changes SEO after the general form was opened.
        $this->connection->update('store_setting', ['value' => json_encode('Yeni açıklama')], ['setting_key' => 'seo.default_description']);
        $form['store_settings[storeName]'] = 'Yeni mağaza adı';
        $this->client->submit($form);
        self::assertResponseRedirects('/yeni/admin/settings');
        self::assertSame('Yeni açıklama', self::getContainer()->get(StoreConfiguration::class)->seoDefaultDescription());
        self::assertFalse(self::getContainer()->get(StoreConfiguration::class)->isSeoIndexingEnabled());
    }

    public function testPaymentSecretsAreEncryptedHiddenPreservedAndUsedImmediately(): void
    {
        $crawler = $this->client->request('GET', '/yeni/admin/settings/payment');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        $form['payment_settings[paymentProvider]'] = 'paytr';
        $form['payment_settings[merchantId]'] = '654321';
        $form['payment_settings[merchantKey]'] = 'private-admin-key';
        $form['payment_settings[merchantSalt]'] = 'private-admin-salt';
        $this->client->submit($form);
        self::assertResponseRedirects('/yeni/admin/settings/payment');
        $stored = $this->connection->fetchAssociative('SELECT * FROM payment_configuration WHERE id = 1');
        self::assertStringNotContainsString('private-admin-key', $stored['merchant_key_encrypted']);
        self::assertStringNotContainsString('private-admin-salt', $stored['merchant_salt_encrypted']);
        $source = self::getContainer()->get(\App\Module\Payment\Gateway\PayTR\StoredPaytrConfiguration::class);
        self::assertTrue($source->current()->isConfigured());
        self::assertSame('654321', $source->current()->merchantId());
        self::assertTrue($source->current()->testMode());
        self::assertSame(
            (new \App\Module\Payment\Gateway\PayTR\PaytrSignature('private-admin-key', 'private-admin-salt'))->callbackHash('TEST', 'success', '100'),
            $source->current()->signature()->callbackHash('TEST', 'success', '100'),
        );

        $crawler = $this->client->request('GET', '/yeni/admin/settings/payment');
        self::assertStringNotContainsString('private-admin-key', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('private-admin-salt', $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('[data-paytr-status]', 'Yapılandırıldı');
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        self::assertSame('', $form['payment_settings[merchantKey]']->getValue());
        $form['payment_settings[testMode]'] = '0';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422); // live mode requires explicit acknowledgement
        self::assertTrue($source->current()->testMode());
        $form['payment_settings[confirmLiveMode]']->tick();
        $this->client->submit($form);
        self::assertResponseRedirects('/yeni/admin/settings/payment');
        self::assertFalse($source->current()->testMode());
        self::assertSame($stored['merchant_key_encrypted'], $this->connection->fetchOne('SELECT merchant_key_encrypted FROM payment_configuration WHERE id = 1'));
        self::assertSame($stored['merchant_salt_encrypted'], $this->connection->fetchOne('SELECT merchant_salt_encrypted FROM payment_configuration WHERE id = 1'));

        $crawler = $this->client->request('GET', '/yeni/admin/settings/payment');
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        $form['payment_settings[confirmLiveMode]']->tick();
        $form['payment_settings[merchantKey]'] = 'replacement-admin-key';
        $this->client->submit($form);
        self::assertResponseRedirects('/yeni/admin/settings/payment');
        self::assertSame(
            (new \App\Module\Payment\Gateway\PayTR\PaytrSignature('replacement-admin-key', 'private-admin-salt'))->refundToken('654321', 'TEST', '1.00'),
            $source->current()->signature()->refundToken('654321', 'TEST', '1.00'),
        );
        $audit = implode('', $this->connection->fetchFirstColumn('SELECT payload FROM commerce_audit_log WHERE action = ?', ['settings.updated']));
        self::assertStringNotContainsString('private-admin-key', $audit);
        self::assertStringNotContainsString('replacement-admin-key', $audit);
    }

    public function testDedicatedSettingsFormsRejectInvalidCsrfWithoutWriting(): void
    {
        $crawler = $this->client->request('GET', '/yeni/admin/settings');
        self::assertSame(['Mağaza ayarları'], $crawler->filter('.nav-link[aria-current="page"]')->each(static fn ($node) => $node->text()));
        foreach (['seo' => 'SEO', 'shipping' => 'Kargo Ayarları', 'payment' => 'Ödeme Sağlayıcı'] as $page => $label) {
            $crawler = $this->client->request('GET', '/yeni/admin/settings/'.$page);
            self::assertSame([$label], $crawler->filter('.nav-link[aria-current="page"]')->each(static fn ($node) => $node->text()));
            $form = $crawler->selectButton('Ayarları kaydet')->form();
            $form[$page.'_settings[_token]'] = 'invalid';
            $this->client->submit($form);
            self::assertResponseStatusCodeSame(422);
        }
        self::assertTrue(self::getContainer()->get(StoreConfiguration::class)->isSeoIndexingEnabled());
        self::assertSame(25_000, self::getContainer()->get(StoreConfiguration::class)->shippingFee());
    }

    public function testPaymentRequestsNeverPersistSecretsInProfilerEvenWithoutAuthentication(): void
    {
        $this->client->enableProfiler();
        $crawler = $this->client->request('GET', '/yeni/admin/settings/payment');
        self::assertResponseIsSuccessful();
        self::assertNull($this->client->getProfile());
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        $form['payment_settings[merchantKey]'] = 'profiler-private-key';
        $form['payment_settings[merchantSalt]'] = 'profiler-private-salt';
        $form['payment_settings[_token]'] = 'invalid-token';
        $this->client->enableProfiler();
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->client->getProfile());
        self::assertStringNotContainsString('profiler-private-key', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('profiler-private-salt', $this->client->getResponse()->getContent());

        $this->client->getCookieJar()->clear();
        $this->client->enableProfiler();
        $this->client->request('POST', '/yeni/admin/settings/payment', ['payment_settings' => ['merchantKey' => 'unauthenticated-private-key']]);
        self::assertResponseRedirects('/yeni/admin/login');
        self::assertNull($this->client->getProfile());
    }

    private function resetDatabaseState(): void
    {
        $this->connection->executeStatement("UPDATE payment_configuration SET merchant_id = '', merchant_key_encrypted = NULL, merchant_salt_encrypted = NULL, test_mode = 1 WHERE id = 1");
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
