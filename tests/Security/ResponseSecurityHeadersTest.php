<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\EventListener\SecurityHeadersSubscriber;
use App\Module\Payment\Gateway\PayTR\StoredPaytrConfiguration;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * What the application says about itself in HTTP headers, and how its session cookie is set.
 *
 * The content policy here is not a generic hardening default; it is built from what this
 * storefront was measured to load. Two consequences are asserted rather than assumed:
 *
 * - `form-action` names the payment provider's host, because the card form posts the customer's
 *   browser straight to it. A policy without it would block a real payment, and one with the
 *   wrong host in it would be a hole — so a test asserts the two cannot drift.
 * - Production does not name the dev hot-reload CDN, because nothing in production loads from
 *   it, and allowing it would leave an unmeasured origin in the policy.
 */
final class ResponseSecurityHeadersTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function publicPages(): iterable
    {
        yield 'catalogue' => ['/yeni/katalog'];
        yield 'homepage' => ['/yeni'];
        yield 'login' => ['/yeni/giris'];
        yield 'registration' => ['/yeni/kayit'];
        yield 'password reset request' => ['/yeni/parolami-unuttum'];
        yield 'blog' => ['/yeni/blog'];
        yield 'information pages' => ['/yeni/bilgi'];
        yield 'sitemap' => ['/yeni/sitemap.xml'];
        yield 'robots' => ['/yeni/robots.txt'];
    }

    /**
     * @param string $path
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('publicPages')]
    public function testEveryPublicResponseCarriesTheBaselineHeaders(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        $headers = $client->getResponse()->headers;

        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'), $path);
        self::assertSame('DENY', $headers->get('X-Frame-Options'), $path);
        self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'), $path);
        self::assertNotNull($headers->get('Permissions-Policy'), $path);
        self::assertNotNull($headers->get('Content-Security-Policy'), $path);
        self::assertNotNull($headers->get('X-Request-Id'), $path);
    }

    public function testThePolicyIsClosedAndForbidsFramingAndPlugins(): void
    {
        $policy = $this->policy();
        $scriptSrc = $this->directive($policy, 'script-src');

        self::assertStringContainsString("default-src 'self'", $policy);
        self::assertStringContainsString("object-src 'none'", $policy);
        self::assertStringContainsString("frame-ancestors 'none'", $policy);
        self::assertStringContainsString("base-uri 'self'", $policy);
        // `*` anywhere would make the rest of the policy decorative.
        self::assertStringNotContainsString('*', $policy);
        self::assertStringNotContainsString('unsafe-eval', $policy);
        // A `data:` URL in script-src would execute an inline payload; in img-src it is harmless
        // and is in fact needed for the theme's inline favicon and upload previews.
        self::assertStringNotContainsString('data:', $scriptSrc);
        self::assertStringContainsString('data:', $this->directive($policy, 'img-src'));
    }

    /**
     * The payment host in `form-action` must be the host the card form actually posts to.
     *
     * These are two independent facts that must agree: the CSP is written as a literal, the form
     * action comes from a configured provider URL. Nothing makes them agree except a test.
     */
    public function testTheFormActionPolicyNamesTheConfiguredPaymentHost(): void
    {
        $client = static::createClient();
        $connection = self::getContainer()->get(\Doctrine\DBAL\Connection::class);
        $connection->beginTransaction();
        \App\Tests\Fixtures\Payment\PaytrConfigurationFixture::configure($connection, self::getContainer()->get(\App\Module\Payment\Gateway\PayTR\PaymentSecretCipher::class));
        $configuration = self::getContainer()->get(StoredPaytrConfiguration::class)->current();

        $paymentUrl = $configuration->paymentUrl();
        $host = parse_url($paymentUrl, PHP_URL_HOST);
        $connection->rollBack();
        self::assertIsString($host);

        $client->request('GET', '/yeni/giris');
        $policy = (string) $client->getResponse()->headers->get('Content-Security-Policy');

        self::assertStringContainsString('form-action', $policy);
        self::assertStringContainsString('https://'.$host, $policy, sprintf('The card form posts to %s, which the policy does not allow.', $paymentUrl));
    }

    public function testTheDevHotReloadHostIsRefusedOutsideDevelopment(): void
    {
        // This environment is `test`, which is treated as non-production. The CDN is allowed here
        // and in dev; the production policy is asserted directly on the service, because a test
        // environment cannot boot a prod kernel without a separate container.
        self::assertStringContainsString('https://cdn.jsdelivr.net', $this->policy());
        self::assertStringNotContainsString('cdn.jsdelivr.net', $this->productionPolicy());
    }

    public function testTransportSecurityIsProductionOnly(): void
    {
        $client = static::createClient();
        $client->request('GET', '/yeni/katalog');
        self::assertNull(
            $client->getResponse()->headers->get('Strict-Transport-Security'),
            'Sending HSTS from a plain-http development host would pin that host to https in a browser for a year.',
        );

        self::assertArrayNotHasKey('Strict-Transport-Security', (new SecurityHeadersSubscriber('test'))->headers());
        $production = (new SecurityHeadersSubscriber('prod'))->headers();
        self::assertArrayHasKey('Strict-Transport-Security', $production);
        self::assertStringContainsString('max-age=', $production['Strict-Transport-Security']);
        // `includeSubDomains` is the operator's decision about their other hosts, not ours.
        self::assertStringNotContainsString('includeSubDomains', $production['Strict-Transport-Security']);
    }

    public function testEveryFormOnAPagePostsSomewhereThePolicyAllows(): void
    {
        // The other half of the payment-host assertion, applied to the markup rather than to the
        // configuration: no rendered form may post somewhere the policy forbids.
        $client = static::createClient();
        $crawler = $client->request('GET', '/yeni/giris');
        $policy = (string) $client->getResponse()->headers->get('Content-Security-Policy');

        self::assertStringContainsString('form-action', $policy);

        foreach ($crawler->filter('form[action]') as $form) {
            self::assertInstanceOf(\DOMElement::class, $form);
            $action = $form->getAttribute('action');
            $target = parse_url($action, PHP_URL_HOST);
            if (null === $target || '' === $target) {
                continue;
            }
            self::assertStringContainsString($target, $policy, sprintf('A form on the login page posts to %s.', $action));
        }
    }

    /**
     * Error responses carry the headers too, with one documented exception.
     *
     * A 404 and a 405 are still pages this application served, and an error page without a
     * content policy or a framing policy is exactly the page somebody pastes into an issue.
     *
     * The exception is `Content-Security-Policy` in **debug** mode: Symfony's own `ErrorListener`
     * removes it from debug error pages on purpose, because the profiler toolbar's inline scripts
     * would be blocked by the application's policy. That is correct framework behaviour and it is
     * debug-only — `ErrorListener::removeCspHeader()` is guarded on `$this->debug` — so in
     * production the policy is present on error pages exactly as on any other. Asserting the
     * debug case would fail against the framework, and asserting nothing would leave the
     * production case unverified, so the test states the difference explicitly.
     */
    public function testErrorResponsesCarryTheSameHeaders(): void
    {
        $client = static::createClient();
        $debug = (bool) self::getContainer()->getParameter('kernel.debug');

        foreach (['/yeni/yok-boyle-bir-sayfa' => 404, '/yeni/odeme/paytr/bildirim' => 405] as $path => $expected) {
            $client->request('GET', $path);

            self::assertSame($expected, $client->getResponse()->getStatusCode(), $path);
            $headers = $client->getResponse()->headers;
            self::assertSame('nosniff', $headers->get('X-Content-Type-Options'), $path);
            self::assertSame('DENY', $headers->get('X-Frame-Options'), $path);
            self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'), $path);
            self::assertNotNull($headers->get('Permissions-Policy'), $path);
            self::assertNotNull($headers->get('X-Request-Id'), $path);

            self::assertSame(
                $debug ? null : $this->policyFrom(new SecurityHeadersSubscriber('prod')),
                $headers->get('Content-Security-Policy'),
                sprintf('%s: the content policy on an error page', $path),
            );
        }
    }

    private function policyFrom(SecurityHeadersSubscriber $subscriber): string
    {
        return (string) $subscriber->headers()['Content-Security-Policy'];
    }

    /**
     * The session cookie's flags, read from the values the container will actually use.
     *
     * Read from `session.storage.options` rather than from the browser client, for two measured
     * reasons. The client records no session cookie on the pages tested here — the login,
     * registration and comparison pages carry *stateless* CSRF tokens or read-only state, so no
     * session starts and no cookie is sent. And a cookie that is only ever observed
     * conditionally is one whose flags are only ever verified sometimes.
     */
    public function testTheSessionCookieIsHttpOnlySameSiteLaxAndFollowsTheRequestForSecure(): void
    {
        $options = self::getContainer()->getParameter('session.storage.options');
        self::assertIsArray($options);

        self::assertTrue($options['cookie_httponly'], 'A session cookie readable from JavaScript is one an XSS can steal.');
        self::assertSame('lax', $options['cookie_samesite'], '`strict` would break the provider redirect back to the order; `lax` is the correct trade.');
        self::assertSame('auto', $options['cookie_secure'], 'A hard `true` silently breaks every session on a plain-http deployment, which is a worse outcome than the alternative it prevents.');
        self::assertGreaterThan(0, $options['gc_maxlifetime']);
    }

    /**
     * Whatever cookies the application does send are HttpOnly and Lax.
     *
     * A property over every cookie rather than over one named session cookie, so a future cookie
     * that forgets either flag fails here instead of shipping. The set may legitimately be empty
     * in this environment — see the note above — so the assertion is about the flags of whatever
     * is present rather than about there being something present.
     */
    public function testEveryCookieTheStorefrontSendsIsHttpOnlyAndLax(): void
    {
        $client = static::createClient();
        $client->request('GET', '/yeni/katalog');

        foreach ($client->getCookieJar()->all() as $cookie) {
            self::assertTrue($cookie->isHttpOnly(), sprintf('%s is readable from JavaScript.', $cookie->getName()));
            self::assertSame('lax', $cookie->getSameSite(), sprintf('%s has the wrong SameSite policy.', $cookie->getName()));
        }

        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testTheCommittedSessionConfigurationStatesEveryFlagExplicitly(): void
    {
        // Read from the committed file rather than from the booted container, because the booted
        // container cannot tell a deliberate setting from a default. This is the same technique
        // the repository already uses for the public base URI.
        $root = \dirname(__DIR__, 2);
        $framework = Yaml::parseFile($root.'/config/packages/framework.yaml');
        self::assertIsArray($framework);

        $session = $framework['framework']['session'];
        self::assertTrue($session['cookie_httponly']);
        self::assertSame('lax', $session['cookie_samesite']);
        self::assertSame('auto', $session['cookie_secure']);
        self::assertGreaterThan(0, $session['gc_maxlifetime']);
    }

    public function testSessionStrictModeIsOnInTheRunningImage(): void
    {
        // Read from the live INI value, not from a configuration key, because Symfony 8 removed
        // `use_strict_mode` from framework configuration: it is a PHP setting, set in the
        // Dockerfile. Asserting the configuration would assert nothing.
        $strict = ini_get('session.use_strict_mode');
        self::assertNotFalse($strict);
        self::assertSame('1', (string) $strict, 'Session strict mode is off, so a session id the server never issued is accepted.');
    }

    public function testTheShippedDockerImageEnablesSessionStrictMode(): void
    {
        $dockerfile = (string) file_get_contents(\dirname(__DIR__, 2).'/Dockerfile');

        self::assertStringContainsString('session.use_strict_mode=1', $dockerfile);
    }

    /**
     * There is deliberately no test here that reads the two .htaccess files and asserts that
     * certain words appear in them. That was the test shipped alongside the rule that took the
     * live site offline: it asserted `FilesMatch`, `php` and `Require all denied` were present
     * and said nothing about what they did, so it passed on a rule that denied every request on
     * the server. `tests/Security/FrontControllerReachabilityTest.php` evaluates those rules
     * against concrete request paths instead, and owns the property.
     */
    public function testTheLiveCredentialsCannotReachABuiltImage(): void
    {
        $dockerignore = (string) file_get_contents(\dirname(__DIR__, 2).'/.dockerignore');

        self::assertStringContainsString('.env.local', $dockerignore, '`COPY . .` would otherwise bake the live provider credentials into an image layer.');
    }

    private function policy(): string
    {
        $client = static::createClient();
        $client->request('GET', '/yeni/katalog');

        return (string) $client->getResponse()->headers->get('Content-Security-Policy');
    }

    /** One directive's value out of a policy string, e.g. `script-src` out of the whole header. */
    private function directive(string $policy, string $name): string
    {
        foreach (explode(';', $policy) as $directive) {
            if (str_starts_with(ltrim($directive), $name.' ')) {
                return trim($directive);
            }
        }

        self::fail(sprintf('The policy has no "%s" directive: %s', $name, $policy));
    }

    /**
     * The production policy, built by the same service with the production environment name.
     *
     * Building it directly rather than booting a prod kernel keeps the assertion cheap and keeps
     * the production and test policies from drifting apart in the test environment's favour.
     */
    private function productionPolicy(): string
    {
        return (new SecurityHeadersSubscriber('prod'))->contentSecurityPolicy();
    }
}
