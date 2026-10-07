<?php

declare(strict_types=1);

namespace App\Tests\Controller\Storefront;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Cms\BlogPost;
use App\Entity\Cms\InformationPage;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Entity\Seo\SeoResourceType;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Module\Seo\SlugRedirectRecorder;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A published URL that somebody else already holds must keep working after the record behind
 * it is renamed.
 *
 * In this codebase the rename is an admin action — the B2B feed only ever creates catalogue
 * records, it never renames an existing one — so today the history protects a corrected name or
 * a typo fix. It is the same mechanism either way: a slug is an address that has already been
 * linked from somewhere, and changing it silently breaks every one of those links.
 */
final class RetiredSlugRedirectTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private SlugRedirectRecorder $recorder;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $manager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->entityManager = $manager;
        $this->recorder = self::getContainer()->get(SlugRedirectRecorder::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheOldProductUrlPermanentlyRedirectsToTheCurrentCanonicalUrl(): void
    {
        $product = $this->publishedProduct('R301-001', 'Eski Ad', 'eski-ad');
        $this->retire(SeoResourceType::Product, (int) $product->id(), 'eski-ad', 'yeni-ad');
        $product->changeSlug('yeni-ad');
        $this->entityManager->flush();

        $this->client->request('GET', '/urun/eski-ad');

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('https://localhost/urun/yeni-ad');
    }

    public function testTheCurrentUrlIsServedDirectlyAndNotRedirected(): void
    {
        $product = $this->publishedProduct('R301-002', 'Yeni Ad', 'yeni-ad-2');
        $this->retire(SeoResourceType::Product, (int) $product->id(), 'onceki-ad-2', 'yeni-ad-2');
        $this->entityManager->flush();

        $this->client->request('GET', '/urun/yeni-ad-2');

        self::assertResponseIsSuccessful();
        self::assertFalse($this->client->getResponse()->headers->has('Location'));
    }

    public function testTheRedirectIsAReal301AndNotA302SoCrawlersTreatItAsPermanent(): void
    {
        $product = $this->publishedProduct('R301-003', 'Kalici', 'kalici-ad');
        $this->retire(SeoResourceType::Product, (int) $product->id(), 'kalici-ad', 'kalici-ad-yeni');
        $product->changeSlug('kalici-ad-yeni');
        $this->entityManager->flush();

        $this->client->request('GET', '/urun/kalici-ad');

        self::assertResponseStatusCodeSame(301);
    }

    public function testARetiredUrlWhoseTargetWasDeletedAnswers404RatherThanRedirectingOnwards(): void
    {
        $product = $this->publishedProduct('R301-004', 'Silinecek', 'silinecek-ad');
        $this->retire(SeoResourceType::Product, (int) $product->id(), 'silinecek-ad', 'silinecek-ad-yeni');
        $this->entityManager->flush();
        $this->entityManager->remove($product);
        $this->entityManager->flush();

        $this->client->request('GET', '/urun/silinecek-ad');

        self::assertResponseStatusCodeSame(404);
    }

    public function testARetiredUrlWhoseTargetWasUnpublishedAnswers404(): void
    {
        $product = $this->publishedProduct('R301-005', 'Gizlenecek', 'gizlenecek-ad');
        $this->retire(SeoResourceType::Product, (int) $product->id(), 'gizlenecek-ad', 'gizlenecek-ad-yeni');
        $product->changeSlug('gizlenecek-ad-yeni');
        $this->entityManager->flush();
        $product->unpublish();
        $this->entityManager->flush();

        $this->client->request('GET', '/urun/gizlenecek-ad');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCategoryBrandAndContentRedirectsWorkTheSameWay(): void
    {
        $category = new Category('Eski Kategori', 'eski-kategori');
        $category->publish();
        $this->entityManager->persist($category);
        $brand = new Brand('Eski Marka', 'eski-marka');
        $brand->publish();
        $this->entityManager->persist($brand);
        $post = new BlogPost('Eski Yazı', 'eski-yazi', 'Özet', 'Gövde');
        $post->setPublished(true);
        $this->entityManager->persist($post);
        $page = new InformationPage('Eski Sayfa', 'eski-sayfa', 'Gövde');
        $page->setPublished(true);
        $this->entityManager->persist($page);
        $this->entityManager->flush();

        $this->retire(SeoResourceType::Category, (int) $category->id(), 'eski-kategori', 'yeni-kategori');
        $category->changeSlug('yeni-kategori');
        $this->retire(SeoResourceType::Brand, (int) $brand->id(), 'eski-marka', 'yeni-marka');
        $brand->changeSlug('yeni-marka');
        $this->retire(SeoResourceType::BlogPost, (int) $post->id(), 'eski-yazi', 'yeni-yazi');
        $post->update('Eski Yazı', 'yeni-yazi', 'Özet', 'Gövde');
        $this->retire(SeoResourceType::InformationPage, (int) $page->id(), 'eski-sayfa', 'yeni-sayfa');
        $page->update('Eski Sayfa', 'yeni-sayfa', 'Gövde');
        $this->entityManager->flush();

        foreach ([
            '/kategori/eski-kategori' => 'https://localhost/kategori/yeni-kategori',
            '/marka/eski-marka' => 'https://localhost/marka/yeni-marka',
            '/blog/eski-yazi' => 'https://localhost/blog/yeni-yazi',
            '/bilgi/eski-sayfa' => 'https://localhost/bilgi/yeni-sayfa',
        ] as $old => $expected) {
            $this->client->request('GET', $old);
            self::assertResponseStatusCodeSame(301, $old);
            self::assertResponseRedirects($expected);
        }
    }

    public function testAnUnknownSlugIsStillAPlain404AndNotARedirect(): void
    {
        $this->client->request('GET', '/urun/hic-boyle-bir-urun-yok');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The content routes are declared GET-only, so a POST never reaches the controller and
     * never reaches this listener either — the router refuses it first. That is the real guard
     * against replaying a state-changing request at a moved address; the `isMethod` check in
     * the listener is defence in depth for a route that later becomes non-GET, and there is
     * nothing here that can reach it.
     */
    public function testAPostToAPublishedSlugRouteIsRefusedByTheRouterNotRedirected(): void
    {
        $product = $this->publishedProduct('R301-006', 'Gonderilen', 'gonderilen-urun');
        $this->retire(SeoResourceType::Product, (int) $product->id(), 'gonderilen-eski', 'gonderilen-urun');
        $this->entityManager->flush();

        $this->client->request('POST', '/urun/gonderilen-eski');

        self::assertResponseStatusCodeSame(405);
        self::assertFalse($this->client->getResponse()->headers->has('Location'));
    }

    private function retire(SeoResourceType $type, int $id, string $oldSlug, string $newSlug): void
    {
        $this->recorder->record($type, $id, wasPublished: true, oldSlug: $oldSlug, newSlug: $newSlug);
    }

    private function publishedProduct(string $sku, string $name, string $slug): Product
    {
        $product = new Product($sku, $name, $slug);
        $product->publish();
        $this->entityManager->persist($product);
        $this->entityManager->persist(new ProductPrice(
            $product,
            Money::ofMinor(12_500, 'TRY'),
            TaxCategory::of('replacement-part'),
            TaxRate::fromBasisPoints(2_000),
        ));
        $this->entityManager->persist(new ProductInventory($product, 3, true));
        $this->entityManager->flush();

        return $product;
    }
}
