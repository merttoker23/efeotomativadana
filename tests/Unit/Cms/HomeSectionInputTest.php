<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cms;

use App\Module\Cms\CmsMediaStorage;
use App\Module\Cms\HomeSectionDraft;
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
    private HomeSectionDraft $drafts;
    private FileBag $files;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/home-section-input-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0o777, true);
        $this->input = new HomeSectionInput(new CmsMediaStorage($this->directory));
        $this->drafts = new HomeSectionDraft();
        $this->files = new FileBag();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory);
    }

    /**
     * A hero slide is nine fields, six of which the theme lets a slide leave out; a draft that
     * predates them arrives with only the four a slide used to have and is filled out from there.
     *
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function slide(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Yeni Ürünler',
            'title' => 'Kampanya',
            'priceLabel' => 'Şu andan itibaren',
            'priceValue' => '2.499,00 TL',
            'primaryText' => '',
            'primaryLink' => '',
            'secondaryText' => '',
            'secondaryLink' => '',
            'image' => $this->storedImage(),
        ], $overrides);
    }

    public function testARowTheAdministratorNeverFilledInIsDroppedRatherThanRejected(): void
    {
        $configuration = $this->input->configuration(HomeSectionType::HeroSlider, ['slides' => [
            $this->slide(),
            $this->slide(['title' => '', 'label' => '', 'priceLabel' => '', 'priceValue' => '', 'image' => '']),
            $this->slide(['title' => '  ', 'label' => '   ', 'priceLabel' => '', 'priceValue' => '', 'image' => '']),
        ]], $this->files);

        self::assertCount(1, $configuration['slides']);
        self::assertSame('Kampanya', $configuration['slides'][0]['title']);
    }

    /**
     * The theme's own three hero compositions: a slide may carry an offer pill, a pair of calls to
     * action, or neither, and the shape stored has to say which without any of them being invented.
     */
    public function testEachHeroCompositionIsStoredWithItsOwnOptionalParts(): void
    {
        $configuration = $this->input->configuration(HomeSectionType::HeroSlider, ['slides' => [
            $this->slide(),
            $this->slide([
                'label' => 'Efe Otomotiv',
                'title' => 'Doğru parçayı bulun',
                'priceLabel' => '',
                'priceValue' => '',
                'primaryText' => 'Hemen başla',
                'primaryLink' => '/yeni/katalog',
                'secondaryText' => 'Kategoriler',
                'secondaryLink' => '/yeni/kategoriler',
            ]),
        ]], $this->files);

        self::assertSame('Şu andan itibaren', $configuration['slides'][0]['priceLabel']);
        self::assertSame('', $configuration['slides'][0]['primaryText']);
        self::assertSame('Hemen başla', $configuration['slides'][1]['primaryText']);
        self::assertSame('/yeni/kategoriler', $configuration['slides'][1]['secondaryLink']);
    }

    public function testAHeroCallToActionWithoutItsOtherHalfIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->input->configuration(HomeSectionType::HeroSlider, ['slides' => [
            $this->slide(['primaryText' => 'Hemen başla', 'primaryLink' => '']),
        ]], $this->files);
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
            $this->slide(['image' => '/uploads/cms/../../etc/passwd']),
        ]], $this->files);
    }

    public function testAUploadedSlideImageIsStoredAndThePathItReturnsIsTheOneThatIsKept(): void
    {
        $this->files->set('slides_0_image_file', $this->upload('hero.png'));

        $configuration = $this->input->configuration(HomeSectionType::HeroSlider, ['slides' => [
            $this->slide(['image' => '']),
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

    /**
     * A hero slide may be nothing but an image: label, title, price and calls to action are all
     * optional, and only the image is required. Three such slides must all be saved, kept in order,
     * and survive a re-edit round trip through the draft.
     */
    public function testThreeImageOnlySlidesAreSavedAndPreservedOnReEdit(): void
    {
        $imageOnly = fn (): array => [
            'label' => '',
            'title' => '',
            'priceLabel' => '',
            'priceValue' => '',
            'primaryText' => '',
            'primaryLink' => '',
            'secondaryText' => '',
            'secondaryLink' => '',
            'image' => $this->storedImage(),
        ];

        $configuration = $this->input->configuration(HomeSectionType::HeroSlider, [
            'slides' => [$imageOnly(), $imageOnly(), $imageOnly()],
        ], $this->files);

        self::assertCount(3, $configuration['slides']);

        $reDraft = $this->drafts->fromConfiguration(HomeSectionType::HeroSlider, $configuration);
        self::assertCount(3, $reDraft['slides']);
        foreach ($reDraft['slides'] as $slide) {
            self::assertSame($this->storedImage(), $slide['image']);
        }
    }

    /**
     * A slide that carries only a library-selected image (no new upload, no text) is not a blank
     * row: the stored image counts as content, so it is kept instead of being dropped.
     */
    public function testLibrarySelectedImageOnlySlideIsNotDroppedAsAnEmptyRow(): void
    {
        $configuration = $this->input->configuration(HomeSectionType::HeroSlider, [
            'slides' => [
                $this->slide(['label' => '', 'title' => '', 'priceLabel' => '', 'priceValue' => '']),
                $this->slide(['label' => '', 'title' => '', 'priceLabel' => '', 'priceValue' => '', 'image' => '']),
            ],
        ], $this->files);

        self::assertCount(1, $configuration['slides']);
        self::assertSame($this->storedImage(), $configuration['slides'][0]['image']);
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

    public function testMobileBannerUsesItsOwnUploadAndSurvivesReEditing(): void
    {
        $this->files->set('slides_0_mobileImage_file', $this->upload('mobile.png'));
        $configuration = $this->input->configuration(HomeSectionType::HeroSlider, [
            'slides' => [$this->slide()],
        ], $this->files);

        self::assertSame($this->storedImage(), $configuration['slides'][0]['image']);
        $mobile = $configuration['slides'][0]['mobileImage'];
        self::assertMatchesRegularExpression('~^/uploads/cms/[a-f0-9]{32}\.png$~', $mobile);
        self::assertFileExists($this->directory.'/'.basename($mobile));
        $draft = $this->drafts->fromConfiguration(HomeSectionType::HeroSlider, $configuration);
        self::assertSame($configuration, $this->input->configuration(HomeSectionType::HeroSlider, $draft, new FileBag()));
    }

    public function testMobileBannerCanBeSelectedAndClearedIndependentlyOfDesktop(): void
    {
        $mobile = '/uploads/cms/'.str_repeat('c', 32).'.webp';
        $configuration = $this->input->configuration(HomeSectionType::HeroSlider, [
            'slides' => [$this->slide(['mobileImage' => $mobile])],
        ], $this->files);
        self::assertSame($mobile, $configuration['slides'][0]['mobileImage']);
        $draft = $this->drafts->fromConfiguration(HomeSectionType::HeroSlider, $configuration);
        $draft['slides'][0]['mobileImage'] = '';
        $cleared = $this->input->configuration(HomeSectionType::HeroSlider, $draft, $this->files);
        self::assertSame('', $cleared['slides'][0]['mobileImage']);
        self::assertSame($this->storedImage(), $cleared['slides'][0]['image']);
    }

    public function testMobileOnlyUploadOnAnEmptyRowStillRequiresADesktopBanner(): void
    {
        $this->files->set('slides_0_mobileImage_file', $this->upload('mobile.png'));
        $this->expectException(\InvalidArgumentException::class);
        $this->input->configuration(HomeSectionType::HeroSlider, HomeSectionType::HeroSlider->emptyDraft(), $this->files);
    }

    public function testMobileUploadStillMatchesItsSlideAfterAnEmptyRowIsDropped(): void
    {
        $this->files->set('slides_1_mobileImage_file', $this->upload('mobile.png'));
        $configuration = $this->input->configuration(HomeSectionType::HeroSlider, [
            'slides' => [HomeSectionType::HeroSlider->emptyDraft()['slides'][0], $this->slide()],
        ], $this->files);
        self::assertCount(1, $configuration['slides']);
        self::assertSame($this->storedImage(), $configuration['slides'][0]['image']);
        self::assertMatchesRegularExpression('~^/uploads/cms/[a-f0-9]{32}\.png$~', $configuration['slides'][0]['mobileImage']);
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
