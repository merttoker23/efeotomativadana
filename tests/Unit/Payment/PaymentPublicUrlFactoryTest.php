<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Module\Payment\PaymentPublicUrlFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class PaymentPublicUrlFactoryTest extends TestCase
{
    public function testReturnAddressesComeFromTheConfiguredPublicBaseNotTheRequest(): void
    {
        $factory = new PaymentPublicUrlFactory($this->router(), 'https://www.efeotomotivadana.com.tr');

        self::assertSame(
            'https://www.efeotomotivadana.com.tr/odeme/sonuc/abc',
            $factory->absolute('storefront_payment_callback', ['token' => 'abc']),
        );
    }

    public function testABasePathIsPreserved(): void
    {
        $factory = new PaymentPublicUrlFactory($this->router(), 'https://www.efeotomotivadana.com.tr/yeni/');

        self::assertSame(
            'https://www.efeotomotivadana.com.tr/yeni/odeme/sonuc/abc',
            $factory->absolute('storefront_payment_callback', ['token' => 'abc']),
        );
    }

    public function testTheTrailingSlashOfTheBaseIsNormalized(): void
    {
        self::assertSame('https://store.test', (new PaymentPublicUrlFactory($this->router(), 'https://store.test/'))->publicBaseUri());
    }

    public function testANonAbsoluteBaseIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DEFAULT_URI must be an absolute URL');

        new PaymentPublicUrlFactory($this->router(), '/yeni');
    }

    public function testAPlainHttpBaseIsRejectedBecauseAProviderWouldSendTheTokenBackInClear(): void
    {
        // A return URL carries the unguessable payment token. Handed to a provider over plain
        // http it would travel in clear, so the base must be https — not merely an absolute URL.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('https');

        new PaymentPublicUrlFactory($this->router(), 'http://localhost');
    }

    public function testALocalHttpsHostIsAcceptedSoDevelopmentAndTestsWork(): void
    {
        $factory = new PaymentPublicUrlFactory($this->router(), 'https://localhost:8443');

        self::assertSame('https://localhost:8443/odeme/sonuc/abc', $factory->absolute('storefront_payment_callback', ['token' => 'abc']));
    }

    private function router(): RouterInterface
    {
        $routes = new RouteCollection();
        $routes->add('storefront_payment_callback', new Route('/odeme/sonuc/{token}'));

        return new class($routes) implements RouterInterface {
            public function __construct(private RouteCollection $routes) {}

            public function setContext(RequestContext $context): void {}

            public function getContext(): RequestContext { return new RequestContext(); }

            public function getRouteCollection(): RouteCollection { return $this->routes; }

            /** @param array<string, scalar> $parameters */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                $path = $this->routes->get($name)->getPath();
                foreach ($parameters as $key => $value) {
                    $path = str_replace('{'.$key.'}', (string) $value, $path);
                }

                return $path;
            }

            /** @return array<string, mixed> */
            public function match(string $pathinfo): array { return []; }
        };
    }
}
