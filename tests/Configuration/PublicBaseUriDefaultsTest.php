<?php

namespace App\Tests\Configuration;

use App\Module\Payment\PaymentPublicUrlFactory;
use App\Shared\PublicUrlGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Guards the public base URI that ships with the repository.
 *
 * The test suite pins DEFAULT_URI to an https value of its own, so it can never notice that
 * every default actually committed to the repository is plain http. The two services that read
 * it refuse a non-https base outright, which means the shipped defaults do not merely produce
 * insecure links — they make the payment and password-reset routes answer 500 while the suite
 * stays green. These tests read the committed files instead of the process environment, so the
 * pin cannot hide them.
 */
final class PublicBaseUriDefaultsTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function shippedDefaultProvider(): iterable
    {
        foreach (self::shippedFiles() as $label => $path) {
            yield $label => [$path];
        }
    }

    #[DataProvider('shippedDefaultProvider')]
    public function testEveryShippedPublicBaseDefaultIsHttps(string $path): void
    {
        $values = self::defaultsIn($path);

        self::assertNotSame([], $values, \sprintf('No DEFAULT_URI found in %s; the file moved or was renamed, and this guard no longer checks it.', basename($path)));

        foreach ($values as $value) {
            self::assertSame(
                'https',
                strtolower((string) parse_url($value, \PHP_URL_SCHEME)),
                \sprintf('%s ships DEFAULT_URI=%s. PublicUrlGenerator and PaymentPublicUrlFactory both refuse a non-https base, so this turns every payment and password-reset route into a 500.', basename($path), $value),
            );
        }
    }

    public function testShippedDefaultsAreAcceptedByTheServicesThatConsumeThem(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);

        foreach (self::shippedFiles() as $path) {
            foreach (self::defaultsIn($path) as $value) {
                self::assertSame($value, (new PaymentPublicUrlFactory($router, $value))->publicBaseUri());
                self::assertSame($value, (new PublicUrlGenerator($router, $value))->publicBaseUri());
            }
        }
    }

    public function testProductionDefaultGeneratesPaytrReturnOnThePublicStoreDomain(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        $values = self::defaultsIn(\dirname(__DIR__, 2).'/.env');
        self::assertCount(1, $values);

        $url = (new PaymentPublicUrlFactory($router, $values[0]))->absolute(
            'storefront_payment_callback',
            ['token' => str_repeat('a', 64)],
        );

        self::assertSame('https://efeotomotivadana.com/odeme/sonuc/'.str_repeat('a', 64), $url);
    }

    public function testProductionComposeDefaultsUseThePublicStoreDomain(): void
    {
        self::assertSame(['https://efeotomotivadana.com'], self::defaultsIn(\dirname(__DIR__, 2).'/compose.yaml'));
    }

    /**
     * The files that decide the public base URI in a real run: Symfony reads `.env` directly, and
     * compose injects a real environment variable, which takes precedence over `.env.local`.
     *
     * @return array<string, string>
     */
    private static function shippedFiles(): array
    {
        $root = \dirname(__DIR__, 2);

        return [
            '.env' => $root.'/.env',
            'compose.yaml' => $root.'/compose.yaml',
            'compose.override.yaml' => $root.'/compose.override.yaml',
        ];
    }

    /**
     * @return list<string>
     */
    private static function defaultsIn(string $path): array
    {
        $contents = (string) file_get_contents($path);
        $values = [];

        foreach (preg_split('/\R/', $contents) as $line) {
            $line = trim((string) $line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }

            if (1 === preg_match('/^DEFAULT_URI\s*[=:]\s*(.+)$/', $line, $match)) {
                $value = trim(preg_replace('/\s+#.*$/', '', $match[1]) ?? '');
                $value = trim($value, "\"'");
            } else {
                continue;
            }

            // A compose line carries a shell-style default; the value that actually ships is the
            // fallback, not the indirection.
            if (1 === preg_match('/^\$\{[^:}]+:-([^}]*)\}$/', $value, $fallback)) {
                $value = $fallback[1];
            }

            if ('' !== $value) {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }
}
