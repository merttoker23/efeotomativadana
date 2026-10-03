<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cms;

use App\Module\Cms\HomeSectionType;
use App\Module\Cms\SectionConfiguration;
use PHPUnit\Framework\TestCase;

/**
 * A product tab's configuration: a title, the source its products come from, and the products
 * themselves when the source is the manual one.
 */
final class ProductTabConfigurationTest extends TestCase
{
    public function testATabStoredBeforeSourcesExistedIsStillValid(): void
    {
        // What a homepage saved before this field existed is exactly: a title and a hand-picked
        // list. It must keep validating without anything having been rewritten.
        $configuration = ['tabs' => [
            ['title' => 'Çok Satanlar', 'slugs' => ['yag-filtresi']],
            ['title' => 'Öne Çıkanlar', 'slugs' => ['fren-balatasi']],
        ]];

        self::assertSame(
            $configuration,
            SectionConfiguration::validate(HomeSectionType::ProductTabs, $configuration),
        );
    }

    public function testEachAutomaticSourceIsAcceptedWithoutProductsOfItsOwn(): void
    {
        foreach (['best_sellers', 'popular', 'on_sale'] as $source) {
            $configuration = ['tabs' => [['title' => 'T', 'source' => $source, 'slugs' => []]]];

            self::assertSame($configuration, SectionConfiguration::validate(HomeSectionType::ProductTabs, $configuration));
        }
    }

    public function testAManualSourceCarriesTheProductsItWasGiven(): void
    {
        $configuration = ['tabs' => [['title' => 'T', 'source' => 'featured', 'slugs' => ['yag-filtresi']]]];

        self::assertSame($configuration, SectionConfiguration::validate(HomeSectionType::ProductTabs, $configuration));
    }

    /** A row with two answers to one question is refused rather than silently resolved. */
    public function testAnAutomaticSourceRefusesProductsOfItsOwn(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SectionConfiguration::validate(HomeSectionType::ProductTabs, [
            'tabs' => [['title' => 'T', 'source' => 'best_sellers', 'slugs' => ['yag-filtresi']]],
        ]);
    }

    /** A manual tab nobody filled in could never be shown, so it is refused at save time. */
    public function testAManualSourceRefusesAnEmptySelection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SectionConfiguration::validate(HomeSectionType::ProductTabs, [
            'tabs' => [['title' => 'T', 'source' => 'featured', 'slugs' => []]],
        ]);
    }

    public function testAnUnknownSourceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SectionConfiguration::validate(HomeSectionType::ProductTabs, [
            'tabs' => [['title' => 'T', 'source' => 'whatever_the_title_says', 'slugs' => []]],
        ]);
    }

    public function testATabMayCarryNoFieldOtherThanItsOwn(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SectionConfiguration::validate(HomeSectionType::ProductTabs, [
            'tabs' => [['title' => 'T', 'source' => 'featured', 'slugs' => ['a'], 'subtitle' => 'nedir']],
        ]);
    }
}