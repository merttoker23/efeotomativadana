<?php

namespace App\Tests\Unit\Cms;

use App\Module\Cms\HomeSectionType;
use App\Module\Cms\SectionConfiguration;
use PHPUnit\Framework\TestCase;

final class HomeSectionConfigurationTest extends TestCase
{
    public function testRejectsUnexpectedKeysAndUnsafeLinks(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SectionConfiguration::validate(HomeSectionType::HeroSlider, ['slides' => [['title' => 'Sale', 'image' => '/uploads/cms/a.webp', 'link' => 'javascript:alert(1)']]]);
    }

    public function testRejectsUnknownConfigurationKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SectionConfiguration::validate(HomeSectionType::Marquee, ['items' => ['Delivery'], 'template' => '{{ 1 + 1 }}']);
    }

    public function testAcceptsConstrainedProductReferences(): void
    {
        self::assertSame(['slugs' => ['brake-pad']], SectionConfiguration::validate(HomeSectionType::ProductCarousel, ['slugs' => ['brake-pad']]));
    }

    /**
     * A hero slide may be image-only: label, title, price and calls to action are all optional,
     * but the image is still required.
     */
    public function testAcceptsAnImageOnlyHeroSlide(): void
    {
        $slide = [
            'label' => '',
            'title' => '',
            'priceLabel' => '',
            'priceValue' => '',
            'primaryText' => '',
            'primaryLink' => '',
            'secondaryText' => '',
            'secondaryLink' => '',
            'image' => '/uploads/cms/'.str_repeat('a', 32).'.jpg',
        ];

        $config = SectionConfiguration::validate(HomeSectionType::HeroSlider, ['slides' => [$slide]]);
        self::assertSame($slide, $config['slides'][0]);
    }

    public function testMobileBannerIsOptionalForExistingSlidesAndValidatedWhenPresent(): void
    {
        $slide = array_fill_keys(HomeSectionType::HeroSlider->rowFields(), '');
        $slide['image'] = '/uploads/cms/'.str_repeat('a', 32).'.jpg';
        unset($slide['mobileImage']);
        self::assertSame($slide, SectionConfiguration::validate(HomeSectionType::HeroSlider, ['slides' => [$slide]])['slides'][0]);
        $slide['mobileImage'] = '/uploads/cms/'.str_repeat('b', 32).'.webp';
        self::assertSame($slide, SectionConfiguration::validate(HomeSectionType::HeroSlider, ['slides' => [$slide]])['slides'][0]);
        $slide['mobileImage'] = 'https://example.com/banner.jpg';
        $this->expectException(\InvalidArgumentException::class);
        SectionConfiguration::validate(HomeSectionType::HeroSlider, ['slides' => [$slide]]);
    }

    public function testRejectsAHeroSlideWithoutAnImage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $slide = [
            'label' => '',
            'title' => '',
            'priceLabel' => '',
            'priceValue' => '',
            'primaryText' => '',
            'primaryLink' => '',
            'secondaryText' => '',
            'secondaryLink' => '',
            'image' => '',
        ];

        SectionConfiguration::validate(HomeSectionType::HeroSlider, ['slides' => [$slide]]);
    }
}
