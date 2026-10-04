<?php

namespace App\Tests\Controller\Cms;

use App\Entity\Cms\BlogPost;
use App\Entity\Customer\AdminUser;
use App\Module\Cms\CmsMediaStorage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

final class BlogCoverTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $manager;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->manager = self::getContainer()->get(EntityManagerInterface::class);
        $admin = new AdminUser('blog-cover-admin@example.com');
        $admin->setPassword('test-only-hash');
        $this->manager->persist($admin);
        $this->manager->flush();
        $this->client->loginUser($admin, 'admin');
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) { $this->connection->rollBack(); }
        foreach ($this->files as $file) { @unlink($file); }
        parent::tearDown();
    }

    public function testUploadSelectPreviewAndRemoveCover(): void
    {
        $crawler = $this->client->request('GET', '/yeni/admin/cms/blog/new');
        self::assertCount(1, $crawler->filter('form[enctype="multipart/form-data"]'));
        $form = $crawler->selectButton('Kaydet')->form();
        $values = array_merge($form->getPhpValues(), ['title' => 'Kapak', 'slug' => 'kapak', 'excerpt' => 'Özet', 'body' => 'Gövde', 'published' => '1']);
        $this->client->request('POST', $form->getUri(), $values, ['cover_image_file' => $this->image()]);
        self::assertResponseRedirects('/yeni/admin/cms/blog');
        $post = $this->manager->getRepository(BlogPost::class)->findOneBy(['slug' => 'kapak']);
        self::assertInstanceOf(BlogPost::class, $post);
        $uploaded = $post->coverImagePath();
        self::assertMatchesRegularExpression('~^/uploads/cms/[a-f0-9]{32}\.png$~', $uploaded);
        $this->files[] = dirname(__DIR__, 3).'/public'.$uploaded;
        $url = '/yeni/admin/cms/blog/'.$post->id().'/edit';
        $crawler = $this->client->request('GET', $url);
        self::assertSame('/yeni'.$uploaded, $crawler->filter('.cms-image-preview')->attr('src'));
        $storage = self::getContainer()->get(CmsMediaStorage::class);
        $selected = $storage->store($this->image());
        $this->files[] = dirname(__DIR__, 3).'/public'.$selected;
        $crawler = $this->client->request('GET', $url);
        $form = $crawler->selectButton('Kaydet')->form();
        $form['cover_image'] = $selected;
        $this->client->submit($form);
        self::assertResponseRedirects('/yeni/admin/cms/blog');
        $post = $this->manager->getRepository(BlogPost::class)->findOneBy(['slug' => 'kapak']);
        self::assertSame($selected, $post->coverImagePath());
        $crawler = $this->client->request('GET', $url);
        $form = $crawler->selectButton('Kaydet')->form();
        $remove = $form['remove_cover'];
        self::assertInstanceOf(ChoiceFormField::class, $remove);
        $remove->tick();
        $this->client->submit($form);
        self::assertResponseRedirects('/yeni/admin/cms/blog');
        $post = $this->manager->getRepository(BlogPost::class)->findOneBy(['slug' => 'kapak']);
        self::assertNull($post->coverImagePath());
        self::assertFileExists(dirname(__DIR__, 3).'/public'.$selected, 'Removing a cover must preserve shared media.');
        $crawler = $this->client->request('GET', '/yeni/blog/kapak');
        self::assertCount(1, $crawler->filter('.blog-post-hero'));
        self::assertCount(0, $crawler->filter('.blog-post-hero img'));
    }

    public function testUnsafeOrMissingLibraryPathIsRejected(): void
    {
        foreach (['/uploads/cms/../../bad.php', '/uploads/cms/'.str_repeat('f', 32).'.png', 'https://example.com/image.png'] as $path) {
            $crawler = $this->client->request('GET', '/yeni/admin/cms/blog/new');
            $form = $crawler->selectButton('Kaydet')->form();
            $values = array_merge($form->getPhpValues(), ['title' => 'Bad', 'slug' => 'bad-cover', 'excerpt' => 'Özet', 'body' => 'Gövde', 'cover_image' => $path]);
            $this->client->request('POST', $form->getUri(), $values);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(0, $this->manager->getRepository(BlogPost::class)->count(['slug' => 'bad-cover']));
        }
    }

    public function testInvalidUploadAndCsrfAreRejectedAndPagesHaveNoCoverControls(): void
    {
        $crawler = $this->client->request('GET', '/yeni/admin/cms/blog/new');
        $form = $crawler->selectButton('Kaydet')->form();
        $values = array_merge($form->getPhpValues(), ['title' => 'Bad', 'slug' => 'bad-cover', 'excerpt' => 'Özet', 'body' => 'Gövde']);
        $file = tempnam(sys_get_temp_dir(), 'blog-invalid');
        file_put_contents($file, '<?php echo "bad";');
        $this->files[] = $file;
        $this->client->request('POST', $form->getUri(), $values, ['cover_image_file' => new UploadedFile($file, 'cover.png', 'image/png', null, true)]);
        self::assertResponseStatusCodeSame(422);
        $values['_token'] = 'invalid-token';
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/yeni/admin/cms/pages/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[name="cover_image_file"]');
        self::assertSelectorNotExists('[name="cover_image"]');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('coverActions')]
    public function testDuplicateSlugPreservesCoverChoiceForCorrectedSubmission(bool $remove): void
    {
        $storage = self::getContainer()->get(CmsMediaStorage::class);
        $original = $storage->store($this->image());
        $selected = $storage->store($this->image());
        foreach ([$original, $selected] as $path) { $this->files[] = dirname(__DIR__, 3).'/public'.$path; }
        $post = new BlogPost('Original', 'original-cover', 'Özet', 'Gövde');
        $post->setCoverImagePath($original);
        $this->manager->persist($post);
        $this->manager->persist(new BlogPost('Taken', 'taken-cover', 'Özet', 'Gövde'));
        $this->manager->flush();
        $url = '/yeni/admin/cms/blog/'.$post->id().'/edit';
        $crawler = $this->client->request('GET', $url);
        $form = $crawler->selectButton('Kaydet')->form();
        $form['slug'] = 'taken-cover';
        $form['cover_image'] = $selected;
        if ($remove) {
            $checkbox = $form['remove_cover'];
            self::assertInstanceOf(ChoiceFormField::class, $checkbox);
            $checkbox->tick();
        }
        $crawler = $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        $corrected = $crawler->selectButton('Kaydet')->form();
        self::assertSame($selected, $corrected['cover_image']->getValue());
        self::assertSame($remove, '1' === ($corrected->getPhpValues()['remove_cover'] ?? null));
        $corrected['slug'] = 'corrected-cover';
        $this->client->submit($corrected);
        self::assertResponseRedirects('/yeni/admin/cms/blog');
        $saved = $this->manager->getRepository(BlogPost::class)->findOneBy(['slug' => 'corrected-cover']);
        self::assertInstanceOf(BlogPost::class, $saved);
        self::assertSame($remove ? null : $selected, $saved->coverImagePath());
    }

    /** @return iterable<string, array{bool}> */
    public static function coverActions(): iterable
    {
        yield 'select' => [false];
        yield 'remove' => [true];
    }

    public function testUploadCanReplaceACoverMissingFromStorage(): void
    {
        $post = new BlogPost('Missing', 'missing-cover', 'Özet', 'Gövde');
        $post->setCoverImagePath('/uploads/cms/'.str_repeat('e', 32).'.png');
        $this->manager->persist($post);
        $this->manager->flush();
        $crawler = $this->client->request('GET', '/yeni/admin/cms/blog/'.$post->id().'/edit');
        $form = $crawler->selectButton('Kaydet')->form();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), ['cover_image_file' => $this->image()]);
        self::assertResponseRedirects('/yeni/admin/cms/blog');
        $saved = $this->manager->getRepository(BlogPost::class)->findOneBy(['slug' => 'missing-cover']);
        self::assertInstanceOf(BlogPost::class, $saved);
        self::assertNotSame($post->coverImagePath(), $saved->coverImagePath());
        $this->files[] = dirname(__DIR__, 3).'/public'.$saved->coverImagePath();
    }

    public function testCoverUploadOverFiveMegabytesIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/yeni/admin/cms/blog/new');
        $form = $crawler->selectButton('Kaydet')->form();
        $values = array_merge($form->getPhpValues(), ['title' => 'Large', 'slug' => 'large-cover', 'excerpt' => 'Özet', 'body' => 'Gövde']);
        $image = $this->image();
        file_put_contents($image->getPathname(), str_repeat('x', CmsMediaStorage::MAX_BYTES + 1));
        $this->client->request('POST', $form->getUri(), $values, ['cover_image_file' => $image]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->manager->getRepository(BlogPost::class)->count(['slug' => 'large-cover']));
    }

    private function image(): UploadedFile
    {
        $file = tempnam(sys_get_temp_dir(), 'blog-cover');
        file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg=='));
        $this->files[] = $file;
        return new UploadedFile($file, 'cover.png', 'image/png', null, true);
    }
}
