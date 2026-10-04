<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shipping;

use App\Module\Checkout\ShippingOptionInterface;
use App\Module\Shipping\LocalManualShippingMethod;
use App\Module\Shipping\ShippingMethodInterface;
use App\Module\Shipping\ShippingMethodNotAvailable;
use App\Module\Shipping\ShippingMethodRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

final class ShippingMethodRegistryTest extends KernelTestCase
{
    public function testTheMethodContractIsTaggedForAutoconfiguration(): void
    {
        $attributes = (new \ReflectionClass(ShippingMethodInterface::class))->getAttributes(AutoconfigureTag::class);

        self::assertCount(1, $attributes);
        self::assertSame(['app.shipping_method'], $attributes[0]->getArguments());
    }

    public function testAnEmptyRegistryResolvesNothing(): void
    {
        $registry = new ShippingMethodRegistry([]);

        self::assertSame([], $registry->keys());
        self::assertFalse($registry->supports('local_standard'));
    }

    public function testTheLocalMethodIsRegisteredAndResolvedCaseInsensitively(): void
    {
        $registry = new ShippingMethodRegistry([new LocalManualShippingMethod()]);

        self::assertTrue($registry->supports('LOCAL_STANDARD'));
        self::assertSame('local_standard', $registry->resolve('  Local_Standard  ')?->key());
        self::assertSame(['local_standard'], $registry->keys());
    }

    public function testTheLocalMethodNeedsNoCarrierAtAll(): void
    {
        // The acceptance criterion that normal commerce stays operable without a carrier API. The
        // reserved `manual` provider key is the whole mechanism: a shipment carrying it is never
        // sent to a carrier, so a store with `shipping.provider` unset or broken still ships.
        self::assertSame(LocalManualShippingMethod::MANUAL_PROVIDER_KEY, (new LocalManualShippingMethod())->providerKey());
        self::assertSame('manual', (new LocalManualShippingMethod())->providerKey());
    }

    public function testSelectingAMethodTheStoreDoesNotOfferFailsLoudly(): void
    {
        $this->expectException(ShippingMethodNotAvailable::class);
        $this->expectExceptionMessage('Shipping method "carrier_express" is not installed.');

        (new ShippingMethodRegistry([new LocalManualShippingMethod()]))->select('carrier_express');
    }

    public function testSelectingAnUnsetMethodFailsLoudly(): void
    {
        $this->expectException(ShippingMethodNotAvailable::class);
        $this->expectExceptionMessage('No shipping method is configured.');

        (new ShippingMethodRegistry([new LocalManualShippingMethod()]))->select(null);
    }

    public function testDuplicateMethodKeysAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate shipping method key "local_standard".');

        new ShippingMethodRegistry([new LocalManualShippingMethod(), new LocalManualShippingMethod()]);
    }

    public function testABlankMethodKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Shipping method key must not be empty.');

        new ShippingMethodRegistry([new BlankMethod()]);
    }

    /**
     * The fulfilment-side registry and the checkout-side option list are two vocabularies over one
     * set of strings. This checks the pair this phase ships: every method it registers is also a
     * service a customer can pick.
     */
    public function testEveryMethodThisPhaseFulfilsIsAlsoOfferedAtCheckout(): void
    {
        self::bootKernel();
        $registry = new ShippingMethodRegistry([new LocalManualShippingMethod()]);
        $offered = array_map(
            static fn (ShippingOptionInterface $option): string => $option->key(),
            [self::getContainer()->get(\App\Module\Checkout\LocalStandardShippingOption::class)],
        );

        self::assertNotEmpty($registry->keys());
        foreach ($registry->keys() as $key) {
            self::assertContains($key, $offered, sprintf('Shipping method "%s" is fulfilable but cannot be chosen at checkout.', $key));
        }
    }

    /**
     * The direction that would actually break a customer: everything offered at checkout must be
     * something the shipping side can perform.
     *
     * Asserted against the container's own registrations, because the unit test above cannot see
     * the service tags and would keep passing while the container registered something the
     * checkout never offers. The reverse direction is deliberately not asserted here: a carrier
     * method may legitimately exist before its checkout option is enabled, and the test
     * environment's carrier method is exactly that.
     */
    public function testTheContainerFulfilsEveryShippingOptionCheckoutOffers(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(\Doctrine\DBAL\Connection::class);
        self::assertInstanceOf(\Doctrine\DBAL\Connection::class, $connection);
        $connection->beginTransaction();

        try {
            $methods = self::getContainer()->get(\App\Module\Shipping\ShippingMethodRegistry::class);
            self::assertInstanceOf(\App\Module\Shipping\ShippingMethodRegistry::class, $methods);

            $checkout = self::getContainer()->get(\App\Module\Checkout\CheckoutManager::class);
            self::assertInstanceOf(\App\Module\Checkout\CheckoutManager::class, $checkout);
            $offered = array_map(
                static fn (\App\Module\Checkout\ShippingOptionInterface $option): string => $option->key(),
                $checkout->view($this->checkoutCustomer())->shippingOptions,
            );

            self::assertNotEmpty($offered);
            foreach ($offered as $key) {
                self::assertTrue(
                    $methods->supports($key),
                    sprintf('Checkout offers shipping method "%s" but the shipping side cannot fulfil it.', $key),
                );
            }
        } finally {
            $connection->rollBack();
        }
    }

    private function checkoutCustomer(): \App\Entity\Customer\CustomerUser
    {
        $customer = new \App\Entity\Customer\CustomerUser('registry-'.bin2hex(random_bytes(6)).'@example.com', 'Efe', 'Yılmaz');
        $customer->setPassword('test-password-hash');
        $em = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(\Doctrine\ORM\EntityManagerInterface::class, $em);
        $em->persist($customer);
        $em->flush();

        return $customer;
    }

    public function testTheLocalMethodIsOfferedAndPricedByTheCheckoutWithoutACarrier(): void
    {
        self::bootKernel();
        $option = self::getContainer()->get(\App\Module\Checkout\LocalStandardShippingOption::class);

        self::assertTrue($option->available(), 'Hand delivery must be available in every environment.');
        self::assertSame('local_standard', (new LocalManualShippingMethod())->key());
        self::assertSame(25_000, $option->cost(\App\Shared\Money\Money::ofMinor(149_999, 'TRY'))->minorAmount());
        self::assertSame(0, $option->cost(\App\Shared\Money\Money::ofMinor(150_000, 'TRY'))->minorAmount());
    }
}

final class BlankMethod implements ShippingMethodInterface
{
    public function key(): string { return '   '; }
    public function label(): string { return 'Blank'; }
    public function providerKey(): string { return 'fake'; }
}
