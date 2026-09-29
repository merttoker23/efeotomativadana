<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use App\Module\Seo\JsonLd;
use PHPUnit\Framework\TestCase;

/**
 * JSON-LD is injected into the page inside a <script> element, so an angle bracket arriving
 * from a product name or a B2B description would otherwise close that element and turn
 * catalogue data into markup the browser executes.
 */
final class JsonLdTest extends TestCase
{
    public function testAngleBracketsAreEncodedSoScriptCannotBeClosedFromData(): void
    {
        $encoded = JsonLd::encode(['name' => '</script><script>alert(1)</script>']);

        self::assertStringNotContainsString('</script>', $encoded);
        self::assertStringNotContainsString('<', $encoded);
        self::assertStringNotContainsString('>', $encoded);
    }

    public function testQuotesAndAmpersandsSurviveAsDataRatherThanBreakingOutOfTheElement(): void
    {
        $encoded = JsonLd::encode(['name' => 'a"b&c\'d']);

        self::assertSame(
            'a"b&c\'d',
            json_decode($encoded, true, flags: \JSON_THROW_ON_ERROR)['name'],
        );
    }

    public function testTurkishCharactersSurviveRoundTripping(): void
    {
        $encoded = JsonLd::encode(['name' => 'Yağ Filtresi Çıtır Lastik Ölçü']);

        self::assertStringContainsString('Yağ Filtresi Çıtır Lastik Ölçü', $encoded);
        self::assertSame('Yağ Filtresi Çıtır Lastik Ölçü', json_decode($encoded, true, flags: \JSON_THROW_ON_ERROR)['name']);
    }

    public function testInvalidUtf8IsRefusedRatherThanEncodedIntoBrokenJson(): void
    {
        $this->expectException(\JsonException::class);

        JsonLd::encode(['name' => "\xB1\x31"]);
    }

    public function testTheSchemaOrgContextIsAlwaysPresent(): void
    {
        self::assertSame(
            ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'Magaza'],
            json_decode(JsonLd::encode(['@type' => 'WebSite', 'name' => 'Magaza']), true, flags: \JSON_THROW_ON_ERROR),
        );
    }
}
