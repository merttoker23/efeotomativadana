<?php

namespace App\Tests\Controller\Cms;

use App\Entity\Cms\BlogPost;
use App\Entity\Cms\HomeSection;
use App\Entity\Cms\InformationPage;
use App\Entity\Customer\AdminUser;
use App\Module\Cms\HomeSectionType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class CmsWorkflowTest extends WebTestCase
{
    private Connection $connection;
    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        static::createClient()->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $manager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) { $this->connection->rollBack(); }
        parent::tearDown();
    }

    public function testHomepageRendersOnlyEnabledSectionsInStoredOrder(): void
    {
        $first = new HomeSection(HomeSectionType::Marquee, 'First', ['items' => ['FIRST-CMS']]);
        $first->setEnabled(true); $first->setSortOrder(20);
        $second = new HomeSection(HomeSectionType::Marquee, 'Second', ['items' => ['SECOND-CMS']]);
        $second->setEnabled(true); $second->setSortOrder(10);
        $hidden = new HomeSection(HomeSectionType::Marquee, 'Hidden', ['items' => ['HIDDEN-CMS']]);
        foreach ([$first, $second, $hidden] as $section) { $this->manager->persist($section); }
        $this->manager->flush();

        $client = static::getClient();
        $client->request('GET', '/yeni/');
        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent() ?: '';
        self::assertLessThan(strpos($html, 'FIRST-CMS'), strpos($html, 'SECOND-CMS'));
        self::assertStringNotContainsString('HIDDEN-CMS', $html);
    }

    public function testDraftContentReturnsNotFoundAndPublishedContentIsReadable(): void
    {
        $post = new BlogPost('Article', 'cms-article', 'Summary', 'Article body');
        $page = new InformationPage('Delivery', 'cms-delivery', 'Delivery body');
        $this->manager->persist($post); $this->manager->persist($page); $this->manager->flush();
        $client = static::getClient();
        $client->request('GET', '/yeni/blog/cms-article'); self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/yeni/bilgi/cms-delivery'); self::assertResponseStatusCodeSame(404);
        $this->connection->executeStatement('UPDATE cms_blog_post SET published = 1 WHERE id = ?', [$post->id()]);
        $this->connection->executeStatement('UPDATE cms_information_page SET published = 1 WHERE id = ?', [$page->id()]);
        $client->request('GET', '/yeni/blog/cms-article'); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('h1', 'Article');
        $client->request('GET', '/yeni/bilgi/cms-delivery'); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('h1', 'Delivery');
    }

    public function testMissingProductReferenceLeavesHomepageAvailable(): void
    {
        $section = new HomeSection(HomeSectionType::ProductCarousel, 'Parts', ['slugs' => ['missing-cms-product']]);
        $section->setEnabled(true);
        $this->manager->persist($section);
        $this->manager->flush();
        static::getClient()->request('GET', '/yeni/');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.home-product-grid .product-card');
    }

    public function testAdminCanCreateAndMoveSectionsButInvalidConfigIsRejected(): void
    {
        $client = static::getClient();
        self::assertInstanceOf(KernelBrowser::class, $client);
        $client->request('GET', '/yeni/admin/cms/home'); self::assertResponseRedirects('/yeni/admin/login');
        $admin = new AdminUser('cms-admin@example.com');
        $admin->setPassword('test-only-hash');
        $this->manager->persist($admin);
        $this->manager->flush();
        $client->loginUser($admin, 'admin');
        $crawler = $client->request('GET', '/yeni/admin/cms/home/new');
        $form = $crawler->selectButton('Save section')->form(['type' => 'marquee', 'title' => 'Shipping', 'configuration' => '{"items":["Fast shipping"]}']);
        $client->submit($form); self::assertResponseRedirects('/yeni/admin/cms/home');
        $crawler = $client->request('GET', '/yeni/admin/cms/home/new');
        $form = $crawler->selectButton('Save section')->form(['type' => 'marquee', 'title' => 'Returns', 'configuration' => '{"items":["Simple returns"]}']);
        $client->submit($form); self::assertResponseRedirects('/yeni/admin/cms/home');
        $crawler = $client->request('GET', '/yeni/admin/cms/home');
        $rows = $crawler->filter('tbody tr');
        self::assertCount(2, $rows);
        $client->submit($rows->eq(1)->filter('form[action*="move/up"]')->form());
        self::assertResponseRedirects('/yeni/admin/cms/home');
        $client->request('GET', '/yeni/admin/cms/home');
        self::assertSelectorTextContains('tbody tr:first-child', 'Returns');
        $crawler = $client->getCrawler();
        $editUrl = $crawler->filter('tbody tr:first-child td a')->attr('href');
        self::assertIsString($editUrl);
        $crawler = $client->request('GET', $editUrl);
        $client->submit($crawler->selectButton('Save section')->form(['configuration' => '{"items":["Updated returns"]}']));
        self::assertResponseRedirects('/yeni/admin/cms/home');
        $crawler = $client->request('GET', '/yeni/admin/cms/home');
        $client->submit($crawler->filter('tbody tr:first-child form[action*="toggle"]')->form());
        self::assertResponseRedirects('/yeni/admin/cms/home');
        $client->request('GET', '/yeni/');
        self::assertSelectorTextContains('.cms-marquee', 'Updated returns');
        $client->request('GET', '/yeni/admin/cms/home/new');
        $form = $client->getCrawler()->selectButton('Save section')->form(['type' => 'marquee', 'title' => 'Invalid', 'configuration' => '{"items":[],"template":"bad"}']);
        $client->submit($form); self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', 'Configuration must contain exactly');
    }
}
