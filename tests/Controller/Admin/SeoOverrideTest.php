<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Cms\BlogPost;
use App\Entity\Cms\InformationPage;
use App\Entity\Customer\AdminUser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Every page falls back to its own facts, so a merchant correcting one awkward product title
 * must be able to do it without being made to fill in the other two fields as well.
 */
final class SeoOverrideTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $manager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->entityManager = $manager;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnonymousUsersCannotReachTheSeoOverrideScreen(): void
    {
        $this->client->request('GET', '/admin/catalog/products/1/seo');

        self::assertResponseRedirects('/admin/login');
    }

    public function testACustomerCannotReachTheSeoOverrideScreen(): void
    {
        $this->client->loginUser(new \Symfony\Component\Security\Core\User\InMemoryUser('viewer@example.com', 'test-only-not-used-for-form-login', ['ROLE_USER']), 'admin');

        $this->client->request('GET', '/admin/catalog/products/1/seo');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAProductCanCarryAMerchantWrittenTitleWithoutTheOtherFields(): void
    {
        $this->loginAdmin();
        $product = $this->product('SEO-A-1', 'Yag Filtresi', 'yag-filtresi-a');

        $crawler = $this->client->request('GET', '/admin/catalog/products/'.$product->id().'/seo');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('SEO ayarlarını kaydet')->form();
        $form['admin_seo[metaTitle]'] = 'MANN HWK 11/2 Yag Filtresi';
        $this->client->submit($form);
        self::assertResponseRedirects();

        self::assertSame(
            'MANN HWK 11/2 Yag Filtresi',
            $this->connection->fetchOne('SELECT meta_title FROM seo_override WHERE resource_type = ? AND resource_id = ?', ['product', $product->id()]),
        );
        self::assertNull($this->connection->fetchOne('SELECT meta_description FROM seo_override WHERE resource_type = ? AND resource_id = ?', ['product', $product->id()]));
    }

    public function testTheOverrideIsWhatTheStorefrontThenPublishes(): void
    {
        $this->loginAdmin();
        $product = $this->product('SEO-A-2', 'Yag Filtresi', 'yag-filtresi-b');

        $crawler = $this->client->request('GET', '/admin/catalog/products/'.$product->id().'/seo');
        $form = $crawler->selectButton('SEO ayarlarını kaydet')->form();
        $form['admin_seo[metaTitle]'] = 'MANN HWK 11/2';
        $form['admin_seo[metaDescription]'] = 'Adana deposundan ayni gun kargoya verilir.';
        $form['admin_seo[noIndex]'] = '1';
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->client->request('GET', '/urun/yag-filtresi-b');

        self::assertResponseIsSuccessful();
        self::assertSame('MANN HWK 11/2', $this->client->getCrawler()->filter('head title')->text());
        self::assertSame(
            'Adana deposundan ayni gun kargoya verilir.',
            $this->client->getCrawler()->filter('head meta[name="description"]')->attr('content'),
        );
        self::assertSame(
            'noindex, follow',
            $this->client->getCrawler()->filter('head meta[name="robots"]')->attr('content'),
        );
    }

    public function testAProductWithNoOverrideRowStillGetsAWorkingScreen(): void
    {
        $this->loginAdmin();
        $product = $this->product('SEO-A-3', 'Fren Diski', 'fren-diski-a');

        $crawler = $this->client->request('GET', '/admin/catalog/products/'.$product->id().'/seo');

        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('SEO ayarlarını kaydet')->form();
        self::assertSame('', $form['admin_seo[metaTitle]']->getValue());
        self::assertFalse((bool) $form['admin_seo[noIndex]']->getValue());
    }

    public function testAnEmptySubmissionStoresNothingRatherThanAnEmptyTitle(): void
    {
        $this->loginAdmin();
        $product = $this->product('SEO-A-4', 'Balata', 'balata-a');

        $crawler = $this->client->request('GET', '/admin/catalog/products/'.$product->id().'/seo');
        $form = $crawler->selectButton('SEO ayarlarını kaydet')->form();
        $form['admin_seo[metaTitle]'] = '   ';
        $form['admin_seo[metaDescription]'] = '';
        $this->client->submit($form);

        self::assertResponseRedirects();
        // A stored empty string would silence the fallback: the page would publish a title of
        // nothing instead of the product's own name.
        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM seo_override WHERE resource_type = ? AND resource_id = ?', ['product', $product->id()]),
        );
    }

    public function testAnOverLongTitleIsRefusedRatherThanTruncated(): void
    {
        $this->loginAdmin();
        $product = $this->product('SEO-A-5', 'Rakam', 'rakam-a');

        $crawler = $this->client->request('GET', '/admin/catalog/products/'.$product->id().'/seo');
        $form = $crawler->selectButton('SEO ayarlarını kaydet')->form();
        $form['admin_seo[metaTitle]'] = str_repeat('a', 256);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM seo_override WHERE resource_type = ? AND resource_id = ?', ['product', $product->id()]));
    }

    public function testEveryContentTypeHasAnOverrideScreen(): void
    {
        $this->loginAdmin();
        $category = new Category('Frenler', 'frenler-a');
        $category->publish();
        $this->entityManager->persist($category);
        $brand = new Brand('Bosch', 'bosch-a');
        $brand->publish();
        $this->entityManager->persist($brand);
        $post = new BlogPost('Yazı', 'yazi-a', 'Özet', 'Gövde');
        $this->entityManager->persist($post);
        $page = new InformationPage('Sayfa', 'sayfa-a', 'Gövde');
        $this->entityManager->persist($page);
        $this->entityManager->flush();

        foreach ([
            '/admin/catalog/categories/'.$category->id().'/seo',
            '/admin/catalog/brands/'.$brand->id().'/seo',
            '/admin/cms/blog/'.$post->id().'/seo',
            '/admin/cms/pages/'.$page->id().'/seo',
        ] as $uri) {
            $this->client->request('GET', $uri);
            self::assertResponseIsSuccessful($uri);
        }
    }

    public function testTheStoreWideSeoSwitchIsOnTheSettingsScreen(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="store_settings[seoIndexingEnabled]"]');
        self::assertSelectorExists('input[name="store_settings[seoDefaultDescription]"]');
    }

    public function testTurningIndexingOffInSettingsClosesEveryPublicPage(): void
    {
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/admin/settings');
        $form = $crawler->selectButton('Ayarları kaydet')->form();
        $indexing = $form['store_settings[seoIndexingEnabled]'];
        // A checkbox has to be unticked rather than assigned a value: assigning a non-string
        // is how a form silently keeps the field it was supposed to clear.
        self::assertInstanceOf(ChoiceFormField::class, $indexing);
        $indexing->untick();
        $form['store_settings[seoDefaultDescription]'] = 'Adana yedek parca magazasi.';
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame(
            'noindex, follow',
            $this->client->getCrawler()->filter('head meta[name="robots"]')->attr('content'),
        );
    }

    private function loginAdmin(): void
    {
        $admin = new AdminUser('seo-admin@example.com');
        $admin->setPassword('test-password-hash');
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        $this->client->loginUser($admin, 'admin');
    }

    /**
     * The CMS editor used to mark a published slug `readonly`, which would have made the
     * redirect history unreachable: the form would submit the old slug back and the controller
     * would correctly record nothing. The rename has to actually be submittable.
     */
    public function testAPublishedCmsPageCanBeRenamedAndItsOldUrlRedirects(): void
    {
        $this->loginAdmin();
        $post = new BlogPost('Eski Yazı', 'eski-yazi-redirect', 'Özet', 'Gövde');
        $post->setPublished(true);
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/admin/cms/blog/'.$post->id().'/edit');
        self::assertResponseIsSuccessful();
        self::assertNull($crawler->filter('#slug')->attr('readonly'), 'A published slug must still be editable.');

        $form = $crawler->selectButton('Kaydet')->form();
        $form['slug'] = 'yeni-yazi-redirect';
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertSame(
            'yeni-yazi-redirect',
            $this->connection->fetchOne('SELECT slug FROM cms_blog_post WHERE id = ?', [$post->id()]),
        );

        $this->client->request('GET', '/blog/eski-yazi-redirect');

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('https://localhost/blog/yeni-yazi-redirect');
    }

    private function product(string $sku, string $name, string $slug): Product
    {
        $product = new Product($sku, $name, $slug);
        $product->publish();
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }
}
