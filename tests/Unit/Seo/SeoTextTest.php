<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use App\Module\Seo\SeoText;
use PHPUnit\Framework\TestCase;

/**
 * The description shown in a search result is derived from prose that was written for a
 * human reading a page, so it arrives as HTML and as several sentences. Turning it into a
 * meta description has to be deterministic and has to respect the length a snippet shows.
 */
final class SeoTextTest extends TestCase
{
    public function testMarkupIsRemovedAndEntitiesAreDecoded(): void
    {
        self::assertSame(
            'Yag filtresi degisim periyodu 10.000 km.',
            (new SeoText())->summary('<p>Yag filtresi degisim <strong>periyodu</strong> 10.000&nbsp;km.</p>'),
        );
    }

    public function testWhitespaceIsCollapsedRatherThanLeftToTheMetaTag(): void
    {
        self::assertSame(
            'Fren diski seti aracin on aksina takilir.',
            (new SeoText())->summary("Fren diski seti\n\n  aracin   on aksina takilir.  "),
        );
    }

    public function testLongProseIsCutAtAWordBoundaryAndNeverExceedsTheCap(): void
    {
        $prose = 'Bu filtre araciniz icin yag devresini korur ve her bakimda degistirilmesi onerilir.';

        $summary = (new SeoText())->summary($prose, 50);

        self::assertLessThanOrEqual(50, mb_strlen($summary));
        self::assertStringEndsWith('…', $summary);

        // The real property is that the clip is a whole-word prefix of the source: whatever
        // follows the last kept character was a space, never the rest of a word.
        $kept = rtrim(mb_substr($summary, 0, mb_strlen($summary) - 1));
        self::assertStringStartsWith($kept, $prose);
        self::assertSame(' ', mb_substr($prose, mb_strlen($kept), 1));
    }

    public function testASingleUnbreakableWordIsStillCapped(): void
    {
        $summary = (new SeoText())->summary(str_repeat('a', 500), 20);

        self::assertLessThanOrEqual(20, mb_strlen($summary));
    }

    public function testProseThatAlreadyFitsIsNotDecoratedWithAnEllipsis(): void
    {
        self::assertSame('Kisa aciklama.', (new SeoText())->summary('Kisa aciklama.', 160));
    }

    public function testEmptyProseYieldsAnEmptySummarySoTheCallerCanFallBack(): void
    {
        $text = new SeoText();

        self::assertSame('', $text->summary(null));
        self::assertSame('', $text->summary('   '));
        self::assertSame('', $text->summary('<p></p>'));
    }
}
