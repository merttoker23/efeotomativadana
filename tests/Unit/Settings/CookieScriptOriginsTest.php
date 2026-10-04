<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settings;

use App\EventListener\SecurityHeadersSubscriber;
use App\Module\Settings\CookieScriptOrigins;
use PHPUnit\Framework\TestCase;

final class CookieScriptOriginsTest extends TestCase
{
    public function testOnlyExplicitHttpsScriptOriginsAreAddedToScriptPolicy(): void
    {
        $snippet = '<script src="https://consent.example.com/cookie.js?key=public"></script><script src="//consent.example.com/other.js"></script><script src="https://cdn.example.com:8443/embed.js"></script><img src="https://image.example.com/a.png"><script src="/local.js"></script><script src="http://insecure.example.com/a.js"></script><script src="https://user:pass@private.example.com/a.js"></script><script src="data:text/javascript,alert(1)"></script><script src="https://bad.example.com;evil/a.js"></script>';
        $origins = CookieScriptOrigins::fromSnippet($snippet);
        self::assertSame(['https://consent.example.com', 'https://cdn.example.com:8443'], $origins);
        $subscriber = new SecurityHeadersSubscriber('prod');
        $policy = $subscriber->contentSecurityPolicy(false, $origins);
        self::assertStringContainsString("script-src 'self' 'unsafe-inline' https://consent.example.com https://cdn.example.com:8443;", $policy);
        self::assertStringContainsString("connect-src 'self' ws: wss:;", $policy);
        self::assertStringNotContainsString('unsafe-eval', $policy);
        self::assertStringNotContainsString('consent.example.com', $subscriber->contentSecurityPolicy());
    }

    public function testBlankAndInlineScriptsDoNotChangeExternalOrigins(): void
    {
        self::assertSame([], CookieScriptOrigins::fromSnippet(null));
        self::assertSame([], CookieScriptOrigins::fromSnippet(''));
        self::assertSame([], CookieScriptOrigins::fromSnippet('<script>window.cookieConsent = true;</script>'));
    }
}
