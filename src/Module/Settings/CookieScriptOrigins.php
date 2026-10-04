<?php

declare(strict_types=1);

namespace App\Module\Settings;

/** Extract only HTTPS script hosts explicitly included in the administrator's snippet. */
final class CookieScriptOrigins
{
    /** @return list<string> */
    public static function fromSnippet(?string $snippet): array
    {
        if (null === $snippet || '' === $snippet) {
            return [];
        }
        $document = new \DOMDocument();
        if (!$document->loadHTML($snippet, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return [];
        }
        $origins = [];
        foreach ($document->getElementsByTagName('script') as $script) {
            $source = trim($script->getAttribute('src'));
            if (str_starts_with($source, '//')) {
                $source = 'https:'.$source;
            }
            $parts = parse_url($source);
            if (false === $parts || 'https' !== strtolower($parts['scheme'] ?? '') || isset($parts['user']) || isset($parts['pass'])) {
                continue;
            }
            $host = strtolower($parts['host'] ?? '');
            if ('' === $host || false === filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                continue;
            }
            $origin = 'https://'.$host;
            if (isset($parts['port']) && 443 !== $parts['port']) {
                $origin .= ':'.$parts['port'];
            }
            $origins[$origin] = true;
        }

        return array_keys($origins);
    }
}
