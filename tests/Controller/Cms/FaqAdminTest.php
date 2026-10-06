<?php

declare(strict_types=1);

namespace App\Tests\Controller\Cms;

use App\Entity\Cms\FaqItem;
use App\Entity\Customer\AdminUser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FaqAdminTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM cms_faq_item');
        $this->manager = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testFaqAdminRequiresAdminAndSupportsCreateEditToggleOrderAndDelete(): void
    {
        $this->client->request('GET', '/yeni/admin/cms/faq');
        self::assertResponseRedirects('/yeni/admin/login');

        $admin = new AdminUser('faq-admin@example.com');
        $admin->setPassword('test-only-hash');
        $this->manager->persist($admin);
        $this->manager->flush();
        $this->client->loginUser($admin, 'admin');

        $crawler = $this->client->request('GET', '/yeni/admin/cms/faq/new');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Kaydet')->form([
            'question' => 'Kargo ne zaman çıkar?',
            'answer' => 'Siparişler hazırlanma süresine göre kargoya verilir.',
            'sort_order' => 20,
            'active' => 1,
        ]));
        self::assertResponseRedirects('/yeni/admin/cms/faq');

        $item = $this->manager->getRepository(FaqItem::class)->findOneBy(['question' => 'Kargo ne zaman çıkar?']);
        self::assertInstanceOf(FaqItem::class, $item);
        self::assertTrue($item->active());
        self::assertSame(20, $item->sortOrder());
        $id = $item->id();
        self::assertNotNull($id);

        $crawler = $this->client->request('GET', '/yeni/admin/cms/faq/'.$id.'/edit');
        $this->client->submit($crawler->selectButton('Kaydet')->form([
            'question' => 'Kargo süresi nedir?',
            'answer' => 'Güncel hazırlık süresi ürün durumuna göre değişebilir.',
            'sort_order' => 20,
            'active' => 1,
        ]));
        self::assertResponseRedirects('/yeni/admin/cms/faq');
        self::assertSame('Kargo süresi nedir?', (string) $this->connection->fetchOne('SELECT question FROM cms_faq_item WHERE id = ?', [$id]));

        $this->client->request('POST', '/yeni/admin/cms/faq/'.$id.'/toggle', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT active FROM cms_faq_item WHERE id = ?', [$id]));

        $crawler = $this->client->request('GET', '/yeni/admin/cms/faq');
        $row = $crawler->filter('tbody tr')->reduce(static fn ($node): bool => str_contains($node->text(), 'Kargo süresi nedir?'));
        self::assertCount(1, $row);
        $this->client->submit($row->selectButton('Pasifleştir')->form());
        self::assertResponseRedirects('/yeni/admin/cms/faq');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT active FROM cms_faq_item WHERE id = ?', [$id]));

        $crawler = $this->client->request('GET', '/yeni/admin/cms/faq');
        $row = $crawler->filter('tbody tr')->reduce(static fn ($node): bool => str_contains($node->text(), 'Kargo süresi nedir?'));
        $this->client->submit($row->selectButton('Sırayı kaydet')->form(['sort_order' => 5]));
        self::assertResponseRedirects('/yeni/admin/cms/faq');
        self::assertSame(5, (int) $this->connection->fetchOne('SELECT sort_order FROM cms_faq_item WHERE id = ?', [$id]));

        $crawler = $this->client->request('GET', '/yeni/admin/cms/faq/'.$id.'/edit');
        $this->client->submit($crawler->selectButton('Sil')->form());
        self::assertResponseRedirects('/yeni/admin/cms/faq');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cms_faq_item WHERE id = ?', [$id]));
    }
}
