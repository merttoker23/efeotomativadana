<?php

declare(strict_types=1);

namespace App\Tests\Controller\Storefront;

use App\Entity\Cms\BlogPost;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The blog archive and a post, drawn as the reference theme draws them.
 *
 * What is checked here is the shape the reference fixes and the data the store really has: one
 * breadcrumb row, one white card, a page title, a three-across grid of cards that are each a single
 * link carrying a cover block, a title and the post's own summary. The post itself is the same card
 * with the title at the top, a wide cover beneath it and the post's excerpt and body under that.
 *
 * Nothing here invents a cover image: `BlogPost` has no cover field, so the reference's own
 * placeholder gradient is what both pages draw, and the test says so rather than skipping it.
 */
final class BlogPagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->manager = self::getContainer()->get(EntityManagerInterface::class);
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
        parent::tearDown();
    }

    public function testTheArchiveIsTheThemesCardGridOfRealPosts(): void
    {
        $this->post('Fren bakımı', 'fren-bakimi', 'Fren balatasının ömrü.', 'Fren balatası değişimi.');
        $this->post('Yağ değişimi', 'yag-degisimi', 'Periyodik bakım.', 'İkinci cümle.');
        $draft = $this->post('Yayımlanmayan', 'yayimlanmayan', 'Görünmemeli.', 'Görünmemeli.');
        $draft->setPublished(false);
        $this->manager->flush();

        $crawler = $this->client->request('GET', '/yeni/blog');

        self::assertResponseIsSuccessful();

        self::assertSame(1, $crawler->filter('nav.breadcrumbs a[href="/yeni/"]')->count());
        self::assertSame('Blog', trim($crawler->filter('main h1.page-title')->text()));

        $cards = $crawler->filter('.blog-list .blog-post-card');
        self::assertSame(2, $cards->count(), 'Only the published posts are listed.');

        // The archive is newest first, and the newest published post is the one this store has.
        $card = $cards->eq(0);
        self::assertSame('/yeni/blog/yag-degisimi', $card->attr('href'));
        // The whole card is the link, and its cover block is decoration inside it.
        self::assertSame(1, $card->filter('.blog-post-cover')->count());
        self::assertSame('Yağ değişimi', trim($card->filter('h2')->text()));
        self::assertStringContainsString('Periyodik bakım.', $card->text());

        self::assertSame(1, $cards->filter('a[href="/yeni/blog/fren-bakimi"]')->count());
    }

    /** A store that has published nothing says so inside the same card the posts would have been. */
    public function testAnEmptyArchiveSaysSo(): void
    {
        $crawler = $this->client->request('GET', '/yeni/blog');

        self::assertResponseIsSuccessful();
        self::assertSame('Blog', trim($crawler->filter('main h1.page-title')->text()));
        self::assertSame(0, $crawler->filter('.blog-post-card')->count());
        self::assertStringContainsString('Henüz yayımlanmış yazı yok.', $crawler->filter('.blog-empty')->text());
    }

    public function testAPostIsTheThemesCardWithTheRealExcerptAndBodyUnderItsTitle(): void
    {
        $this->post('Fren bakımı', 'fren-bakimi', 'Fren balatasının ömrü.', "İlk cümle.\n\nİkinci cümle.");
        $this->manager->flush();

        $crawler = $this->client->request('GET', '/yeni/blog/fren-bakimi');

        self::assertResponseIsSuccessful();

        self::assertSame('Fren bakımı', trim($crawler->filter('article.blog-article h1.page-title')->text()));
        self::assertSame(1, $crawler->filter('article.blog-article .blog-post-hero')->count());
        self::assertSame('Fren balatasının ömrü.', trim($crawler->filter('.blog-lead')->text()));
        self::assertStringContainsString('İlk cümle.', $crawler->filter('.blog-prose')->text());
        self::assertStringContainsString('İkinci cümle.', $crawler->filter('.blog-prose')->text());

        // The reference's breadcrumb: home, then back to the archive.
        self::assertSame(1, $crawler->filter('nav.breadcrumbs a[href="/yeni/blog"]')->count());
    }

    public function testAnUnpublishedPostIsNotReachable(): void
    {
        $post = $this->post('Gizli', 'gizli', 'Özet.', 'Gövde.');
        $post->setPublished(false);
        $this->manager->flush();

        $this->client->request('GET', '/yeni/blog/gizli');

        self::assertResponseStatusCodeSame(404);
    }

    private function post(string $title, string $slug, string $excerpt, string $body): BlogPost
    {
        $post = new BlogPost($title, $slug, $excerpt, $body);
        $post->setPublished(true);
        $this->manager->persist($post);
        $this->manager->flush();

        return $post;
    }

    private function clear(): void
    {
        $this->manager->createQueryBuilder()->delete(BlogPost::class, 'post')->getQuery()->execute();
    }
}
