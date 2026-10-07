<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Entity\Cms\BlogPost;
use App\Entity\Cms\InformationPage;
use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Seo\SeoOverride;
use App\Entity\Seo\SeoResourceType;
use App\Module\Seo\SeoOverrideReader;
use App\Module\Seo\SlugRedirectRecorder;
use App\Module\Seo\SlugRedirectResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Redirect history is what keeps a published URL alive after a slug is renamed.
 *
 * The rename is an admin action in this codebase — the B2B feed only creates catalogue records
 * and never renames an existing one — so in practice the history protects a corrected name or a
 * fixed typo. Either way the old address is already linked from somewhere, and losing it is an
 * outage no log records.
 */
final class SlugRedirectTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private SlugRedirectRecorder $recorder;
    private SlugRedirectResolver $resolver;
    private SeoOverrideReader $overrides;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->recorder = self::getContainer()->get(SlugRedirectRecorder::class);
        $this->resolver = self::getContainer()->get(SlugRedirectResolver::class);
        $this->overrides = self::getContainer()->get(SeoOverrideReader::class);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testARenamedPublishedProductKeepsItsOldUrlPointingAtTheNewOne(): void
    {
        $product = $this->product('SEO-001', 'Yag Filtresi', 'yag-filtresi');

        $this->recorder->record(SeoResourceType::Product, (int) $product->id(), true, 'yag-filtresi', 'yag-filtresi-yeni');
        $this->entityManager->flush();

        $redirect = $this->resolver->find(SeoResourceType::Product, 'yag-filtresi');
        self::assertNotNull($redirect);
        self::assertSame((int) $product->id(), $redirect->resourceId());
    }

    public function testARenamedDraftRecordsNothingBecauseItsUrlWasNeverPublic(): void
    {
        $this->product('SEO-002', 'Taslak', 'taslak');

        $this->recorder->record(SeoResourceType::Product, 1, wasPublished: false, oldSlug: 'taslak', newSlug: 'taslak-yeni');

        self::assertNull($this->resolver->find(SeoResourceType::Product, 'taslak'));
    }

    public function testASaveThatDidNotChangeTheSlugRecordsNothing(): void
    {
        $this->product('SEO-003', 'Filtre', 'filtre');

        $this->recorder->record(SeoResourceType::Product, 1, wasPublished: true, oldSlug: 'filtre', newSlug: 'filtre');

        self::assertNull($this->resolver->find(SeoResourceType::Product, 'filtre'));
    }

    public function testReusingARetiredSlugRepointsTheHistoryAtWhoeverHoldsItNow(): void
    {
        $first = $this->product('SEO-004', 'Ilk', 'paylasilan-slug');
        $this->recorder->record(SeoResourceType::Product, (int) $first->id(), true, 'paylasilan-slug', 'ilk-ad');
        $first->changeSlug('ilk-ad');
        $this->entityManager->flush();

        // A later product takes over the slug the first one retired, then retires it too.
        $second = $this->product('SEO-005', 'Ikinci', 'paylasilan-slug');
        $this->recorder->record(SeoResourceType::Product, (int) $second->id(), true, 'paylasilan-slug', 'ikinci-ad');
        $second->changeSlug('ikinci-ad');
        $this->entityManager->flush();

        $redirect = $this->resolver->find(SeoResourceType::Product, 'paylasilan-slug');
        self::assertNotNull($redirect);
        self::assertSame((int) $second->id(), $redirect->resourceId());
        self::assertSame('https://localhost/urun/ikinci-ad', $this->resolver->targetUrlFor($redirect));
        self::assertSame(
            1,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM seo_slug_redirect WHERE resource_type = ? AND old_slug = ?',
                ['product', 'paylasilan-slug'],
            ),
        );
    }

    public function testTheSameSlugCanBeRetiredOncePerContentTypeWithoutColliding(): void
    {
        $product = $this->product('SEO-006', 'Ortak', 'ortak-slug');
        $category = new Category('Ortak Kategori', 'ortak-slug');
        $category->publish();
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        $this->recorder->record(SeoResourceType::Product, (int) $product->id(), true, 'ortak-slug', 'a');
        $this->recorder->record(SeoResourceType::Category, (int) $category->id(), true, 'ortak-slug', 'b');
        $this->entityManager->flush();

        self::assertNotNull($this->resolver->find(SeoResourceType::Product, 'ortak-slug'));
        self::assertNotNull($this->resolver->find(SeoResourceType::Category, 'ortak-slug'));
    }

    public function testAnUnpublishedTargetIsNotRedirectedToBecauseTheTargetWouldBeA404(): void
    {
        $product = $this->product('SEO-007', 'Gizli', 'gizli-urun');
        $this->recorder->record(SeoResourceType::Product, (int) $product->id(), true, 'gizli-urun', 'gizli-urun-2');
        $this->entityManager->flush();
        $product->unpublish();
        $this->entityManager->flush();

        $redirect = $this->resolver->find(SeoResourceType::Product, 'gizli-urun');
        self::assertNotNull($redirect);
        self::assertNull($this->resolver->targetUrlFor($redirect), 'An unpublished product has no public URL to redirect to.');
    }

    public function testADeletedTargetIsNotRedirectedToEither(): void
    {
        $product = $this->product('SEO-008', 'Silinen', 'silinen-urun');
        $id = (int) $product->id();
        $this->recorder->record(SeoResourceType::Product, $id, true, 'silinen-urun', 'silinen-urun-2');
        $this->entityManager->flush();
        $this->entityManager->remove($product);
        $this->entityManager->flush();

        $redirect = $this->resolver->find(SeoResourceType::Product, 'silinen-urun');
        self::assertNotNull($redirect);
        self::assertNull($this->resolver->targetUrlFor($redirect));
    }

    public function testTheRedirectTargetIsTheCurrentCanonicalUrlOfTheLiveResource(): void
    {
        $product = $this->product('SEO-009', 'Canli', 'canli-urun');
        $this->recorder->record(SeoResourceType::Product, (int) $product->id(), true, 'canli-urun', 'canli-urun-2');
        $this->entityManager->flush();
        $product->changeSlug('canli-urun-3');
        $this->entityManager->flush();

        $redirect = $this->resolver->find(SeoResourceType::Product, 'canli-urun');
        self::assertNotNull($redirect);
        self::assertSame('https://localhost/urun/canli-urun-3', $this->resolver->targetUrlFor($redirect));
    }

    /**
     * Each content type retires its slug into the same history table, so each one has to find
     * its way back to the route it actually lives at rather than a guessed one.
     */
    public function testEveryContentTypeRedirectsToItsOwnLocalRoute(): void
    {
        $expected = [
            [SeoResourceType::Product, '/urun/'],
            [SeoResourceType::Category, '/kategori/'],
            [SeoResourceType::Brand, '/marka/'],
            [SeoResourceType::BlogPost, '/blog/'],
            [SeoResourceType::InformationPage, '/bilgi/'],
        ];

        foreach ($expected as [$type, $path]) {
            $id = $this->publishedIdFor($type);
            $key = str_replace('_', '-', $type->value);
            $oldSlug = 'eski-'.$key;
            $newSlug = 'yeni-'.$key;
            $this->recorder->record($type, $id, wasPublished: true, oldSlug: $oldSlug, newSlug: $newSlug);
            $this->moveTo($type, $id, $newSlug);

            $redirect = $this->resolver->find($type, $oldSlug);
            self::assertNotNull($redirect, $type->value);
            self::assertSame(
                'https://localhost'.$path.$newSlug,
                $this->resolver->targetUrlFor($redirect),
            );
        }
    }

    public function testAnOverrideIsPersistedOncePerContentTypeAndReadBackForMetadata(): void
    {
        $product = $this->product('SEO-010', 'Urun', 'urun-10');

        $override = SeoOverride::for(SeoResourceType::Product, (int) $product->id());
        $override->retitle('Özel Başlık');
        $override->hideFromIndex(true);
        $this->entityManager->persist($override);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $found = $this->overrides->for(SeoResourceType::Product, (int) $product->id());
        self::assertSame('Özel Başlık', $found->title);
        self::assertTrue($found->noIndex);
    }

    public function testAContentTypeWithNoOverrideRowYieldsAnEmptyOverrideRatherThanNull(): void
    {
        $product = $this->product('SEO-012', 'Urun', 'urun-12');

        $overrides = $this->overrides->for(SeoResourceType::Product, (int) $product->id());

        self::assertNull($overrides->title);
        self::assertNull($overrides->description);
        self::assertNull($overrides->noIndex);
    }

    public function testASecondOverrideRowForTheSameResourceIsRejectedByTheDatabase(): void
    {
        $product = $this->product('SEO-011', 'Urun', 'urun-11');
        $first = SeoOverride::for(SeoResourceType::Product, (int) $product->id());
        $first->retitle('Birinci');
        $this->entityManager->persist($first);
        $this->entityManager->flush();

        $second = SeoOverride::for(SeoResourceType::Product, (int) $product->id());
        $second->retitle('İkinci');
        $this->entityManager->persist($second);

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    private function moveTo(SeoResourceType $type, int $id, string $slug): void
    {
        $entity = match ($type) {
            SeoResourceType::Product => $this->entityManager->find(Product::class, $id),
            SeoResourceType::Category => $this->entityManager->find(Category::class, $id),
            SeoResourceType::Brand => $this->entityManager->find(Brand::class, $id),
            SeoResourceType::BlogPost => $this->entityManager->find(BlogPost::class, $id),
            SeoResourceType::InformationPage => $this->entityManager->find(InformationPage::class, $id),
        };
        self::assertNotNull($entity);

        match (true) {
            $entity instanceof Product => $entity->changeSlug($slug),
            $entity instanceof Category => $entity->changeSlug($slug),
            $entity instanceof Brand => $entity->changeSlug($slug),
            $entity instanceof BlogPost => $entity->update($entity->title(), $slug, $entity->excerpt(), $entity->body()),
            default => $entity->update($entity->title(), $slug, $entity->body()),
        };
        $this->entityManager->flush();
    }

    private function publishedIdFor(SeoResourceType $type): int
    {
        $key = str_replace('_', '-', $type->value);

        return match ($type) {
            SeoResourceType::Product => (int) $this->product('SEO-R-'.$key, 'R '.$key, 'r-'.$key)->id(),
            SeoResourceType::Category => $this->categoryId(new Category('C '.$key, 'c-'.$key)),
            SeoResourceType::Brand => $this->brandId(new Brand('B '.$key, 'b-'.$key)),
            SeoResourceType::BlogPost => $this->postId(new BlogPost('Blog '.$key, 'blog-'.$key, 'Özet', 'Gövde')),
            SeoResourceType::InformationPage => $this->pageId(new InformationPage('Sayfa '.$key, 'sayfa-'.$key, 'Gövde')),
        };
    }

    private function categoryId(Category $category): int
    {
        $category->publish();
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return (int) $category->id();
    }

    private function brandId(Brand $brand): int
    {
        $brand->publish();
        $this->entityManager->persist($brand);
        $this->entityManager->flush();

        return (int) $brand->id();
    }

    private function postId(BlogPost $post): int
    {
        $post->setPublished(true);
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        return (int) $post->id();
    }

    private function pageId(InformationPage $page): int
    {
        $page->setPublished(true);
        $this->entityManager->persist($page);
        $this->entityManager->flush();

        return (int) $page->id();
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
