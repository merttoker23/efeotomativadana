<?php

declare(strict_types=1);

namespace App\Module\Seo;

/**
 * Derives a meta description from prose that was written to be read on a page.
 *
 * A search result shows a bounded number of characters, so an unbounded description is both
 * useless and untestable: two different code paths could each produce a different length. The
 * cap is therefore applied here, once, and always at a word boundary — cutting mid-word puts
 * half a word in the result, which reads as broken rather than truncated.
 */
final class SeoText
{
    public const DESCRIPTION_LIMIT = 160;

    private const ELLIPSIS = "\u{2026}";

    public function summary(?string $html, int $limit = self::DESCRIPTION_LIMIT): string
    {
        $text = $this->plain($html);
        $limit = max(1, $limit);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        // Leave room for the ellipsis so the result, not just the words, respects the cap.
        $clipped = mb_substr($text, 0, $limit - mb_strlen(self::ELLIPSIS));
        $lastBreak = mb_strrpos($clipped, ' ');
        if (false !== $lastBreak && $lastBreak > 0) {
            $clipped = mb_substr($clipped, 0, $lastBreak);
        }

        return rtrim($clipped).self::ELLIPSIS;
    }

    private function plain(?string $html): string
    {
        if (null === $html) {
            return '';
        }

        // strip_tags first, then decode: an encoded "&lt;script&gt;" in the source would
        // otherwise survive as literal "<script>" text and be counted as real characters.
        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        // \s alone does not match U+00A0 under PCRE's Unicode mode, and a non-breaking space
        // left inside a meta description is invisible but still occupies the snippet.
        $collapsed = preg_replace('/[\s\p{Z}\x{00A0}]+/u', ' ', $text);

        return trim(is_string($collapsed) ? $collapsed : $text);
    }
}
