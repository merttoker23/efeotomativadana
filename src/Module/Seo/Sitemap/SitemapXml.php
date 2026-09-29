<?php

declare(strict_types=1);

namespace App\Module\Seo\Sitemap;

/**
 * Renders the two sitemap documents.
 *
 * The XML is built by hand with explicit escaping rather than through a template, for one
 * reason: `<loc>` and `<lastmod>` contain values that came from a catalogue, a B2B feed or an
 * admin form, and a template that interpolates them unescaped is a well-formedness bug waiting
 * for a product named "Filtre <b>". `htmlspecialchars` with ENT_XML1 is the encoding an XML
 * document actually expects, and `simplexml_load_string` in the tests is what proves the
 * result parses rather than merely looks right.
 */
final class SitemapXml
{
    /**
     * @param list<array{loc: string, lastmod: ?string}> $entries
     */
    public static function urlSet(array $entries): string
    {
        $body = '';
        foreach ($entries as $entry) {
            $body .= '  <url>'."\n"
                .'    <loc>'.self::escape($entry['loc']).'</loc>'."\n";
            if (null !== $entry['lastmod']) {
                $body .= '    <lastmod>'.self::escape($entry['lastmod']).'</lastmod>'."\n";
            }
            $body .= '  </url>'."\n";
        }

        return self::document('urlset', $body);
    }

    /**
     * @param list<array{loc: string, lastmod: ?string}> $entries
     */
    public static function index(array $entries): string
    {
        $body = '';
        foreach ($entries as $entry) {
            $body .= '  <sitemap>'."\n"
                .'    <loc>'.self::escape($entry['loc']).'</loc>'."\n";
            if (null !== $entry['lastmod']) {
                $body .= '    <lastmod>'.self::escape($entry['lastmod']).'</lastmod>'."\n";
            }
            $body .= '  </sitemap>'."\n";
        }

        return self::document('sitemapindex', $body);
    }

    private static function document(string $root, string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<'.$root.' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .$body
            .'</'.$root.'>'."\n";
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_XML1, 'UTF-8');
    }
}
