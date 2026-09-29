<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use App\Entity\Seo\SeoResourceType;
use App\Entity\Seo\SeoOverride;
use App\Entity\Seo\SlugRedirect;
use App\Module\Seo\SeoOverrides;
use PHPUnit\Framework\TestCase;

final class SeoResourceTest extends TestCase
{
    public function testEachPublicContentTypeKnowsTheLocalRouteItLivesAt(): void
    {
        self::assertSame('storefront_catalog_product', SeoResourceType::Product->routeName());
        self::assertSame('storefront_catalog_category', SeoResourceType::Category->routeName());
        self::assertSame('storefront_catalog_brand', SeoResourceType::Brand->routeName());
        self::assertSame('storefront_blog_show', SeoResourceType::BlogPost->routeName());
        self::assertSame('storefront_information_show', SeoResourceType::InformationPage->routeName());
    }

    public function testARetiredSlugMustLookLikeTheSlugsTheCatalogActuallyUses(): void
    {
        $redirect = new SlugRedirect(SeoResourceType::Product, 7, 'yag-filtresi');

        self::assertSame('yag-filtresi', $redirect->oldSlug());
        self::assertSame(7, $redirect->resourceId());
        self::assertSame(SeoResourceType::Product, $redirect->resourceType());
    }

    public function testASlugThatCouldNotHaveBeenAPublicUrlIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SlugRedirect(SeoResourceType::Product, 7, 'Yag Filtresi');
    }

    public function testAnEmptySlugIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SlugRedirect(SeoResourceType::Product, 7, '   ');
    }

    public function testAnOverrideIsOptionalFieldByFieldSoNoFieldIsEverRequired(): void
    {
        $override = new SeoOverride(SeoResourceType::Product, 7, null, null, false);

        self::assertNull($override->title());
        self::assertNull($override->description());
        self::assertFalse($override->noIndex());
        self::assertEquals(new SeoOverrides(), $override->overrides());
    }

    public function testAnOverrideCanCorrectTheTitleWithoutTouchingTheDescription(): void
    {
        $override = SeoOverride::for(SeoResourceType::Product, 7);
        $override->retitle('MANN HWK 11/2');

        self::assertSame('MANN HWK 11/2', $override->title());
        self::assertNull($override->description());
    }

    public function testAnOverrideLongerThanTheSnippetItLandsInIsRefusedRatherThanTruncated(): void
    {
        $override = SeoOverride::for(SeoResourceType::Product, 7);

        $this->expectException(\InvalidArgumentException::class);

        $override->redescribe(str_repeat('a', SeoOverride::DESCRIPTION_LIMIT + 1));
    }

    public function testABlankOverrideIsStoredAsAbsentSoItFallsBackRatherThanAsAnEmptyString(): void
    {
        $override = SeoOverride::for(SeoResourceType::Category, 3);
        $override->retitle('   ');
        $override->redescribe('');

        self::assertNull($override->title());
        self::assertNull($override->description());
    }
}
