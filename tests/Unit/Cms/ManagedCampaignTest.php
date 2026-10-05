<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cms;

use App\Module\Cms\{HomeSectionType, HomeSectionDraft, HomeSectionInput, CmsMediaStorage, SectionConfiguration};
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\FileBag;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class ManagedCampaignTest extends TestCase
{
    public function testLegacyAnnouncementEditsAsOneRepeatableItem(): void
    {
        self::assertSame(['text' => 'Aynı gün kargo'], SectionConfiguration::validate(HomeSectionType::AnnouncementBar, ['text' => 'Aynı gün kargo']));
        $draft = (new HomeSectionDraft())->fromConfiguration(HomeSectionType::AnnouncementBar, ['text' => 'Aynı gün kargo']);
        self::assertSame([['text' => 'Aynı gün kargo']], $draft['items']);
    }

    public function testMultipleAnnouncementsUseExistingInputAndRowOrdering(): void
    {
        $drafts = new HomeSectionDraft();
        $draft = $drafts->fromConfiguration(HomeSectionType::AnnouncementBar, ['items' => ['Kargo', 'İade']]);
        $draft = $drafts->fromRequest(HomeSectionType::AnnouncementBar, ['items_count' => '2', 'items_0_text' => 'Kargo', 'items_1_text' => 'İade', '_action' => 'move-row-up', '_index' => '1'], $draft);
        $input = new HomeSectionInput(new CmsMediaStorage(sys_get_temp_dir()));
        self::assertSame(['items' => ['İade', 'Kargo']], $input->configuration(HomeSectionType::AnnouncementBar, $draft, new FileBag()));
    }

    public function testAnnouncementRejectsEmptyAndOversizedLists(): void
    {
        foreach ([[], array_fill(0, 21, 'Kargo'), [''], [123]] as $items) {
            try {
                SectionConfiguration::validate(HomeSectionType::AnnouncementBar, ['items' => $items]);
                self::fail('Invalid announcement accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTickerKeepsLegacyTextEscapedAndDuplicatesHidden(): void
    {
        foreach ([['text' => '<b>Kargo</b>'], ['items' => ['<b>Kargo</b>', 'İade']]] as $data) {
            $html = $this->twig()->render('storefront/home/sections/_announcement_bar.html.twig', ['section' => ['data' => $data]]);
            self::assertStringContainsString('&lt;b&gt;Kargo&lt;/b&gt;', $html);
            self::assertSame(1, substr_count($html, 'class="notice-ticker-group" aria-hidden="true"'));
            self::assertStringContainsString('●', $html);
            self::assertStringContainsString('storefront-shell#dismissAnnouncement', $html);
        }
    }

    public function testPopupValidatesTextOnlyAndDraftBooleanDelay(): void
    {
        $config = ['description' => '', 'image' => '', 'mobileImage' => '', 'cta' => '', 'link' => '', 'delay' => 5, 'allowDismiss' => true];
        self::assertSame($config, SectionConfiguration::validate(HomeSectionType::PopupAd, $config));
        $drafts = new HomeSectionDraft();
        $draft = $drafts->fromRequest(HomeSectionType::PopupAd, ['delay' => '9', 'allowDismiss' => '1'], $drafts->fromConfiguration(HomeSectionType::PopupAd, $config));
        self::assertSame(9, $draft['delay']);
        self::assertTrue($draft['allowDismiss']);
        $input = new HomeSectionInput(new CmsMediaStorage(sys_get_temp_dir()));
        self::assertSame(9, $input->configuration(HomeSectionType::PopupAd, $draft, new FileBag())['delay']);
    }

    public function testPopupRejectsUnsafeLinksImagesAndInvalidOptions(): void
    {
        $config = ['description' => '', 'image' => '', 'mobileImage' => '', 'cta' => 'Keşfet', 'link' => '/yeni/urunler', 'delay' => 5, 'allowDismiss' => true];
        foreach ([['link', 'javascript:alert(1)'], ['link', '//example.com'], ['link', 'https://example.com'], ['image', '/uploads/other.jpg'], ['mobileImage', 'https://example.com/a.jpg'], ['delay', -1], ['delay', 301], ['delay', '5'], ['allowDismiss', 'yes'], ['cta', '']] as [$key, $value]) {
            try {
                SectionConfiguration::validate(HomeSectionType::PopupAd, array_replace($config, [$key => $value]));
                self::fail('Invalid popup accepted: '.$key);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testPopupMediaSelectionsSurviveAdminSubmission(): void
    {
        $desktop = '/uploads/cms/'.str_repeat('a', 32).'.jpg';
        $mobile = '/uploads/cms/'.str_repeat('b', 32).'.webp';
        $drafts = new HomeSectionDraft();
        $draft = $drafts->fromRequest(HomeSectionType::PopupAd, [
            'popup_0_image' => $desktop, 'popup_0_mobileImage' => $mobile,
            'description' => 'Kampanya', 'delay' => '12', 'allowDismiss' => '1',
        ], HomeSectionType::PopupAd->emptyDraft());
        $input = new HomeSectionInput(new CmsMediaStorage(sys_get_temp_dir()));
        $config = $input->configuration(HomeSectionType::PopupAd, $draft, new FileBag());
        self::assertSame($desktop, $config['image']);
        self::assertSame($mobile, $config['mobileImage']);
        $draft = $drafts->fromRequest(HomeSectionType::PopupAd, ['popup_0_image' => $desktop, 'popup_0_mobileImage' => '', 'delay' => '5'], $draft);
        $config = $input->configuration(HomeSectionType::PopupAd, $draft, new FileBag());
        self::assertSame('', $config['mobileImage']);
        self::assertFalse($config['allowDismiss']);
    }

    public function testPopupRendersResponsiveImagesAndTextOnlyWithAccessibleLabels(): void
    {
        $desktop = '/uploads/cms/'.str_repeat('a', 32).'.jpg';
        $mobile = '/uploads/cms/'.str_repeat('b', 32).'.webp';
        foreach ([[$desktop, $mobile], [$desktop, ''], ['', '']] as [$image, $mobileImage]) {
            $html = $this->twig()->render('storefront/home/sections/_popup_ad.html.twig', ['section' => ['title' => '<b>Kampanya</b>', 'data' => ['description' => '<script>test</script>', 'image' => $image, 'mobileImage' => $mobileImage, 'cta' => 'Keşfet', 'link' => '/yeni/urunler', 'delay' => 5, 'allowDismiss' => true, 'campaignKey' => 'campaign-1']]]);
            self::assertStringContainsString('aria-labelledby="campaign-popup-title"', $html);
            self::assertStringContainsString('aria-describedby="campaign-popup-description"', $html);
            self::assertStringContainsString('&lt;b&gt;Kampanya&lt;/b&gt;', $html);
            self::assertStringNotContainsString('<script>', $html);
            if ($image) {
                self::assertStringContainsString('src="'.$desktop.'"', $html);
                self::assertStringContainsString('srcset="'.($mobileImage ?: $desktop).'"', $html);
            } else {
                self::assertStringNotContainsString('<picture>', $html);
            }
        }
    }

    private function twig(): Environment
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates'), ['strict_variables' => true, 'autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('media_url', static fn (string $path): string => $path));
        return $twig;
    }
}
