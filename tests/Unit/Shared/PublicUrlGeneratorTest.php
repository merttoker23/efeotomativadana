<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\PublicUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;

/**
 * A password reset link is built from this, and a Host header must never influence it.
 */
final class PublicUrlGeneratorTest extends TestCase
{
    public function testTheLinkIsBuiltFromTheConfiguredBase(): void
    {
        // The route already carries the application's own path prefix, so the base is host-only.
        $generator = $this->generator('https://magaza.example');

        self::assertSame(
            'https://magaza.example/parola-sifirla/abc123',
            $generator->absolute('customer_password_reset', ['token' => 'abc123']),
        );
    }

    public function testTrailingSlashesInTheBaseDoNotDoubleUp(): void
    {
        self::assertSame(
            'https://magaza.example/parola-sifirla/abc123',
            $this->generator('https://magaza.example/')->absolute('customer_password_reset', ['token' => 'abc123']),
        );
    }

    public function testAHostileHostHeaderCannotChangeTheLink(): void
    {
        // The request claims to be for another host entirely. The link must not follow it,
        // because a reset token delivered to an attacker's host is account takeover.
        $request = Request::create('https://magaza.example/yeni/parolami-unuttum');
        $request->headers->set('Host', 'attacker.example');
        $request->server->set('HTTP_HOST', 'attacker.example');

        $generator = $this->generator('https://magaza.example');

        self::assertStringNotContainsString(
            'attacker.example',
            $generator->absolute('customer_password_reset', ['token' => 'abc123']),
        );
    }

    public function testTheBaseIsReportedBackForTemplatesThatNeedIt(): void
    {
        self::assertSame('https://magaza.example', $this->generator('https://magaza.example/')->publicBaseUri());
    }

    public function testAPlainHttpBaseIsRefusedSoLinksAreNeverEmittedInsecurely(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->generator('http://magaza.example');
    }

    public function testABaseThatIsNotAUrlIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->generator('not a url');
    }

    private function generator(string $base): PublicUrlGenerator
    {
        $collection = new RouteCollection();
        $collection->add('customer_password_reset', new Route('/parola-sifirla/{token}'));

        return new PublicUrlGenerator(
            new Router(new ClosureLoader(), static fn (): RouteCollection => $collection, ['cache_dir' => null], new RequestContext()),
            $base,
        );
    }
}
