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
        self::assertSelectorNotExists('.top-sellers .seller');
        self::assertSelectorNotExists('.home-section .product-grid .product-card');
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
        $client->request('GET', '/yeni/admin/cms/home/new?type=marquee');
        $form = $client->getCrawler()->selectButton('Bölümü kaydet')->form([
            'title' => 'Shipping',
            'items_count' => 1,
            'items_0_text' => 'Fast shipping',
        ]);
        $client->submit($form); self::assertResponseRedirects('/yeni/admin/cms/home');
        $client->request('GET', '/yeni/admin/cms/home/new?type=marquee');
        $form = $client->getCrawler()->selectButton('Bölümü kaydet')->form([
            'title' => 'Returns',
            'items_count' => 1,
            'items_0_text' => 'Simple returns',
        ]);
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
        $client->submit($crawler->selectButton('Bölümü kaydet')->form(['items_0_text' => 'Updated returns']));
        self::assertResponseRedirects('/yeni/admin/cms/home');
        $crawler = $client->request('GET', '/yeni/admin/cms/home');
        $client->submit($crawler->filter('tbody tr:first-child form[action*="toggle"]')->form());
        self::assertResponseRedirects('/yeni/admin/cms/home');
        $client->request('GET', '/yeni/');
        self::assertSelectorTextContains('.marquee', 'Updated returns');
        $client->request('GET', '/yeni/admin/cms/home/new?type=marquee');
        $form = $client->getCrawler()->selectButton('Bölümü kaydet')->form(['title' => 'Invalid', 'items_count' => 1]);
        $client->submit($form); self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', 'Bölüm kayıtları boş bırakılamaz');
    }

    /**
     * A section form used to be a textarea named `configuration` holding raw JSON, which meant any
     * key the page never mentioned could be stored and interpreted. There is no such field left:
     * the editor is generated from each section type's declared fields and the server reads only
     * those names, so a submission carrying a configuration document is simply ignored.
     */
    public function testNoSectionFormOffersARawConfigurationFieldAndAConfigurationPostIsIgnored(): void
    {
        $client = static::getClient();
        self::assertInstanceOf(KernelBrowser::class, $client);
        $admin = new AdminUser('cms-json-admin@example.com');
        $admin->setPassword('test-only-hash');
        $this->manager->persist($admin);
        $this->manager->flush();
        $client->loginUser($admin, 'admin');

        foreach (HomeSectionType::cases() as $type) {
            $crawler = $client->request('GET', '/yeni/admin/cms/home/new?type='.$type->value);
            self::assertResponseIsSuccessful();
            self::assertSame(0, $crawler->filter('textarea[name="configuration"]')->count(), $type->value);
            self::assertSame(0, $crawler->filter('input[name="configuration"]')->count(), $type->value);
        }

        $crawler = $client->request('GET', '/yeni/admin/cms/home/new?type=marquee');
        $form = $crawler->selectButton('Bölümü kaydet')->form();
        $client->request('POST', $form->getUri(), array_merge($form->getPhpValues(), [
            'configuration' => '{"items":["Injected"],"template":"admin/some-other-template"}',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->manager->getRepository(HomeSection::class)->count());
    }
}
