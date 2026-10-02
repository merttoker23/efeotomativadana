<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Module\Payment\FakePaymentGateway;
use App\Module\Shipping\FakeShippingProvider;
use App\Module\Shipping\TestCarrierShippingMethod;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class ProductionProviderServicesTest extends TestCase
{
    public function testFakeProvidersAreNotRegisteredOutsideTheTestEnvironment(): void
    {
        foreach (['prod', 'dev', 'staging'] as $environment) {
            $container = $this->loadServices($environment);

            foreach ([FakePaymentGateway::class, FakeShippingProvider::class, TestCarrierShippingMethod::class] as $class) {
                // Symfony retains an abstract placeholder for excluded classes until compilation.
                self::assertTrue($container->getDefinition($class)->isAbstract(), $class.' must not be instantiable in '.$environment);
                self::assertTrue($container->getDefinition($class)->hasTag('container.excluded'));
            }
        }
    }

    public function testFakesRemainAutoconfiguredForDeterministicTests(): void
    {
        $container = $this->loadServices('test');

        foreach ([FakePaymentGateway::class, FakeShippingProvider::class, TestCarrierShippingMethod::class] as $class) {
            self::assertTrue($container->getDefinition($class)->isAutoconfigured());
        }
    }

    private function loadServices(string $environment): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', \dirname(__DIR__, 2));
        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'), $environment);
        $loader->load('services.yaml');

        return $container;
    }
}
