<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cms;

use App\Module\Cms\CmsMediaStorage;
use App\Module\Cms\HomeSectionInput;
use App\Module\Cms\HomeSectionType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\FileBag;

/**
 * A draft becomes a configuration, and the only way it can happen is through here.
 *
 * The properties that matter are that an unfilled row disappears instead of becoming a
 * validation error, that a chosen image is re-checked against the storage that owns the naming,
 * and that the result is still judged by the domain — this class narrows what it is handed, it
 * does not decide what is allowed.
 */
final class HomeSectionInputTest extends TestCase
{
    private string $directory;
    private HomeSectionInput $input;
    private FileBag $files;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/home-section-input-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0o777, true);
        $this->input = new HomeSectionInput(new CmsMediaStorage($this->directory));
        $this->files = new FileBag();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testARowTheAdministratorNeverFilledInIsDroppedRatherThanRejected(): void
    {
        $configuration = $this->input->configuration(HomeSectionType::HeroSlider, ['slides' => [
            ['title' => 'Kampanya', 'description' => 'Açıklama', 'image' => $this->storedImage(), 'link' => '/yeni/katalog'],
            ['title' => '', 'description' => '', 'image' => '', 'link' => ''],
            ['title' => '  ', 'description' => '   ', 'image' => '', 'link' => ''],
        ]], $this->files);

        self::assertCount(1, $configuration['slides']);
        self::assertSame('Kampanya', $configuration['slides'][0]['title']);
    }
    public function testTextIsTrimmedAndBoundedBeforeTheDomainSeesIt(): void
    {
        $configuration = $this->input->configuration(HomeSectionType::AnnouncementBar, [
            'text' => '  '.str_repeat('a', 900).'  ',
        ], $this->files);

        self::assertSame(500, mb_strlen($configuration['text']));
    }

    public function testAnImageTheStorageCouldNotHaveWrittenIsNotAccepted(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->input->configuration(HomeSectionType::HeroSlider, ['slides' => [
            ['title' => 'Kampanya', 'description' => 'Açıklama', 'image' => '/uploads/cms/../../etc/passwd', 'link' => '/yeni/katalog'],
        ]], $this->files);
    }

    public function testAUploadedSlideImageIsStoredAndThePathItReturnsIsTheOneThatIsKept(): void
    {
        $this->files->set('slides_0_file', $this->upload('hero.png'));

        $configuration = $this->input->configuration(HomeSectionType::HeroSlider, ['slides' => [
            ['title' => 'Kampanya', 'description' => 'Açıklama', 'image' => '', 'link' => '/yeni/katalog'],
        ]], $this->files);

        $image = $configuration['slides'][0]['image'];
        self::assertMatchesRegularExpression('~^/uploads/cms/[a-f0-9]{32}\.png$~', $image);
        self::assertFileExists($this->directory.'/'.basename($image));
    }

    public function testAnUntouchedRowKeepsTheImageItAlreadyHad(): void
    {
        $stored = $this->storedImage();

        $configuration = $this->input->configuration(HomeSectionType::BannerGrid, ['banners' => [
            ['title' => 'İndirim', 'image' => $stored, 'link' => '/yeni/katalog'],
        ]], $this->files);

        self::assertSame($stored, $configuration['banners'][0]['image']);
    }

    public function testBlankMarqueeWordsAreRemovedAndTheRestKeepTheirOrder(): void
    {
        $configuration = $this->input->configuration(HomeSectionType::Marquee, ['items' => [
            ['text' => ' Birinci '], ['text' => '  '], ['text' => 'İkinci'],
        ]], $this->files);

        self::assertSame(['Birinci', 'İkinci'], $configuration['items']);
    }

    public function testABlogFeedLimitIsClampedToWhatTheDomainAccepts(): void
    {
        self::assertSame(12, $this->input->configuration(HomeSectionType::BlogFeed, ['limit' => 99], $this->files)['limit']);
        self::assertSame(1, $this->input->configuration(HomeSectionType::BlogFeed, ['limit' => 0], $this->files)['limit']);
    }

    public function testDuplicateSelectionsCollapseAndOrderIsKept(): void
    {
        $configuration = $this->input->configuration(HomeSectionType::ProductCarousel, [
            'slugs' => ['c', 'a', 'b', 'a', ' '],
        ], $this->files);

        self::assertSame(['c', 'a', 'b'], $configuration['slugs']);
    }

    public function testAConfigurationIsStillJudgedByTheDomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // A link must be a local path. The form offers the store's own routes, but the rule is the
        // domain's and it is not weakened because the field is now a form control.
        $this->input->configuration(HomeSectionType::BannerGrid, ['banners' => [
            ['title' => 'İndirim', 'image' => $this->storedImage(), 'link' => 'https://example.com'],
        ]], $this->files);
    }

    private function storedImage(): string
    {
        return '/uploads/cms/'.str_repeat('b', 32).'.jpg';
    }

    private function upload(string $name): UploadedFile
    {
        $path = $this->directory.'/'.$name;
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        ));

        return new UploadedFile($path, 'orijinal.png', 'image/png', null, true);
    }
}
