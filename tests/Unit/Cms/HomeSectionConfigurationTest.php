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
}
