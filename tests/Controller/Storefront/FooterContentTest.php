<?php

declare(strict_types=1);

namespace App\Tests\Controller\Storefront;

use App\Entity\Cms\InformationPage;
use App\Module\Settings\StoreConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Footer'ın iki işi: yöneticinin yayınladığı sayfaları listelemek ve doldurduğu iletişim
 * bilgilerini göstermek — ve ikisini de yarım bırakmamak.
 *
 * Sıralı iki adımdan oluşur: önce bilgi sayfalarının CMS'ten gelip gelmediği, sonra mağaza
 * iletişim bilgilerinin ayarlardan gelip gelmediği. Her ikisi de footer'ın aynı okuma
 * katmanından geçtiği için aynı yerde doğrulanır.
 */
final class FooterContentTest extends WebTestCase
{
    private const INFO_COLUMN = 'section[aria-labelledby="footer-info-title"]';

    /** Every setting the footer prints. Each test states all of them rather than assuming. */
    private const CONTACT_KEYS = ['store.phone', 'store.city', 'store.district', 'store.contact_email'];

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        // No reboot, so the wrapping transaction survives the requests below and every row this
        // test creates is rolled back with it. That also means the settings memo has to be reset by
        // hand after a direct database write, which `storeSetting()` does.
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $manager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;

        // Another test may have left a contact e-mail behind; the footer's own default is that
        // none of these is set, and this test is where that default is asserted.
        foreach (self::CONTACT_KEYS as $key) {
            $this->storeSetting($key, null);
        }
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testFooterListsEveryPublishedPageByTitleAndNoDraft(): void
    {
        $this->informationPage('Gizlilik Politikası', 'gizlilik-politikasi', published: true);
        $this->informationPage('Mesafeli Satış Politikası', 'mesafeli-satis', published: true);
        $this->informationPage('Yayımlanmayan Taslak', 'taslak', published: false);

        $crawler = $this->client->request('GET', '/yeni/bilgi');

        self::assertResponseIsSuccessful();
        self::assertSame('Bilgi Sayfaları', $crawler->filter('#footer-info-title')->text());
        self::assertSame(
            ['Gizlilik Politikası', 'Mesafeli Satış Politikası'],
            $this->informationColumnTitles($crawler),
        );
    }

    public function testEachFooterPageLinksToItsOwnAddress(): void
    {
        $this->informationPage('Gizlilik Politikası', 'gizlilik-politikasi', published: true);

        $crawler = $this->client->request('GET', '/yeni/bilgi');
        $href = $crawler->filter(self::INFO_COLUMN.' a[href$="/bilgi/gizlilik-politikasi"]')->attr('href');
        self::assertSame('/yeni/bilgi/gizlilik-politikasi', $href);

        $this->client->request('GET', (string) $href);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Gizlilik Politikası');
    }

    public function testTheBlogStaysOutOfTheInformationPageList(): void
    {
        $this->informationPage('Gizlilik Politikası', 'gizlilik-politikasi', published: true);

        $crawler = $this->client->request('GET', '/yeni/bilgi');

        self::assertSame(
            ['/yeni/blog'],
            $crawler->filter(self::INFO_COLUMN.' a[href*="/blog"]')->each(static fn ($node) => (string) $node->attr('href')),
        );
        self::assertCount(2, $crawler->filter(self::INFO_COLUMN.' a'));
    }

    public function testAnEmptyContactBlockIsNotRenderedAtAll(): void
    {
        $this->storeSetting('store.contact_email', null);

        $crawler = $this->client->request('GET', '/yeni/bilgi');

        self::assertSelectorNotExists('.site-footer .footer-address');
        self::assertSelectorExists('.site-footer .footer-bottom .payments img');
    }

    public function testFilledContactDetailsRenderAsLinks(): void
    {
        $this->storeSetting('store.phone', '+90 322 123 45 67');
        $this->storeSetting('store.city', 'Adana');
        $this->storeSetting('store.district', 'Seyhan');
        $this->storeSetting('store.contact_email', 'iletisim@example.com');

        $crawler = $this->client->request('GET', '/yeni/bilgi');

        self::assertSelectorTextContains('.site-footer .footer-address', 'Adana / Seyhan');
        self::assertSelectorTextContains('.site-footer .footer-address', '+90 322 123 45 67');
        self::assertSelectorTextContains('.site-footer .footer-address', 'iletisim@example.com');
        self::assertSame('tel:+903221234567', $crawler->filter('.site-footer .footer-address a[href^="tel:"]')->attr('href'));
        self::assertSame('mailto:iletisim@example.com', $crawler->filter('.site-footer .footer-address a[href^="mailto:"]')->attr('href'));
    }

    public function testValuesThatWereNeverValidatedAreNotPrinted(): void
    {
        // Written straight to the database, bypassing the form: a restored backup or a bad import
        // would look exactly like this. An undialable number, an unknown province and a district
        // that belongs to a different province are each refused on the way out.
        $this->storeSetting('store.phone', '444');
        $this->storeSetting('store.city', 'Adaa');
        $this->storeSetting('store.district', 'Seyhan');

        $crawler = $this->client->request('GET', '/yeni/bilgi');
        self::assertSelectorNotExists('.site-footer .footer-address');
        self::assertStringNotContainsString('444', $crawler->filter('.site-footer')->text());

        $this->storeSetting('store.phone', '+90 322 123 45 67');
        $this->storeSetting('store.city', 'Adana');
        $this->storeSetting('store.district', 'Şehitkamil');

        $crawler = $this->client->request('GET', '/yeni/bilgi');
        self::assertSelectorTextContains('.site-footer .footer-address', '+90 322 123 45 67');
        self::assertStringNotContainsString('Şehitkamil', $crawler->filter('.site-footer')->text());
    }

    public function testAPhoneOnItsOwnIsEnoughToPrintTheAddress(): void
    {
        $this->storeSetting('store.phone', '+90 532 123 45 67');

        $crawler = $this->client->request('GET', '/yeni/bilgi');

        self::assertSelectorTextContains('.site-footer .footer-address', '+90 532 123 45 67');
        self::assertCount(1, $crawler->filter('.site-footer .footer-address > *'));
        self::assertSame('tel:+905321234567', $crawler->filter('.site-footer .footer-address a')->attr('href'));
    }

    /** @return list<string> */
    private function informationColumnTitles(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        return $crawler->filter(self::INFO_COLUMN.' a[href*="/bilgi/"]')->each(static fn ($node) => $node->text());
    }

    private function informationPage(string $title, string $slug, bool $published): void
    {
        $page = new InformationPage($title, $slug, $title.' içeriği');
        $page->setPublished($published);
        $this->manager->persist($page);
        $this->manager->flush();
    }

    private function storeSetting(string $key, ?string $value): void
    {
        // An upsert, not an update: a key nobody has ever saved has no row yet, and an update that
        // matches nothing would leave this test passing for the wrong reason.
        $this->connection->executeStatement(
            'INSERT INTO store_setting (setting_key, value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$key, json_encode($value)],
        );
        // The kernel is deliberately not rebooted here, so the request-scoped memo of the store's
        // settings is dropped explicitly instead of by `kernel.reset`.
        self::getContainer()->get(StoreConfiguration::class)->reset();
    }
}
