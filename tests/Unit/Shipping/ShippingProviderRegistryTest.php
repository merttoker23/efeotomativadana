<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shipping;

use App\Module\Payment\SanitizedFailure;
use App\Module\Shipping\FakeShippingProvider;
use App\Module\Shipping\Gateway\ShipmentAddress;
use App\Module\Shipping\Gateway\ShipmentCancellationInstruction;
use App\Module\Shipping\Gateway\ShipmentCancellationOutcome;
use App\Module\Shipping\Gateway\ShipmentCreationInstruction;
use App\Module\Shipping\Gateway\ShipmentCreationOutcome;
use App\Module\Shipping\Gateway\ShipmentItem;
use App\Module\Shipping\Gateway\ShipmentLabel;
use App\Module\Shipping\Gateway\ShipmentLabelRequest;
use App\Module\Shipping\Gateway\ShipmentStatusReport;
use App\Module\Shipping\Gateway\ShipmentStatusRequest;
use App\Module\Shipping\Gateway\ShippingProviderInterface;
use App\Module\Shipping\ShippingProviderNotAvailable;
use App\Module\Shipping\ShippingProviderRegistry;
use App\Module\Shipping\ShipmentState;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

final class ShippingProviderRegistryTest extends TestCase
{
    public function testAnEmptyRegistryResolvesNothing(): void
    {
        $registry = new ShippingProviderRegistry([], 'prod');

        self::assertNull($registry->resolve(null));
        self::assertFalse($registry->supports('fake'));
    }

    public function testASelectedProviderIsResolvedCaseInsensitively(): void
    {
        $registry = new ShippingProviderRegistry([new FakeShippingProvider()], 'test');

        self::assertTrue($registry->supports('FAKE'));
        self::assertSame('fake', $registry->resolve('FaKe')?->key());
    }

    public function testTheFakeCarrierCannotSilentlyRemainEnabledForProduction(): void
    {
        $registry = new ShippingProviderRegistry([new FakeShippingProvider()], 'prod');

        $this->expectException(ShippingProviderNotAvailable::class);
        $this->expectExceptionMessage('not permitted in the prod environment');

        $registry->resolve('fake');
    }

    /**
     * A deployment called `staging` still has real parcels and real customers behind it, so the
     * fake is refused there too. Only dev, test and local are exempt.
     *
     * `supports()` keeps answering true in every environment on purpose: the key is *installed*,
     * and installation is not the question. Whether this environment may use it is a separate
     * answer, which is why `resolve()` throws instead of `supports()` lying.
     */
    public function testTheFakeCarrierIsRefusedInAnyEnvironmentThatIsNotDevelopment(): void
    {
        foreach (['prod', 'staging', 'production', 'preview'] as $environment) {
            $registry = new ShippingProviderRegistry([new FakeShippingProvider()], $environment);

            self::assertTrue($registry->isProduction(), $environment);
            self::assertTrue($registry->supports('FAKE'), $environment);

            try {
                $registry->resolve('fake');
                self::fail(sprintf('The fake carrier must be refused in %s.', $environment));
            } catch (ShippingProviderNotAvailable $exception) {
                self::assertStringContainsString('not permitted in the '.$environment.' environment', $exception->getMessage());
            }
        }
    }

    public function testTheFakeCarrierStaysAvailableForDevelopmentAndTest(): void
    {
        foreach (['dev', 'test', 'local'] as $environment) {
            $registry = new ShippingProviderRegistry([new FakeShippingProvider()], $environment);

            self::assertFalse($registry->isProduction(), $environment);
            self::assertSame('fake', $registry->resolve('fake')?->key(), $environment);
        }
    }

    public function testAnUnconfiguredStoreStillResolvesNothingRatherThanFailing(): void
    {
        // A store that has not picked a carrier is a legitimate state: it ships by hand. Failing
        // here would make the registry refuse a configuration the plan explicitly supports.
        $registry = new ShippingProviderRegistry([new FakeShippingProvider()], 'prod');

        self::assertNull($registry->resolve(null));
    }

    public function testAFlowThatCannotProceedWithoutACarrierFailsLoudly(): void
    {
        $this->expectException(ShippingProviderNotAvailable::class);
        $this->expectExceptionMessage('No shipping provider is configured');

        (new ShippingProviderRegistry([new FakeShippingProvider()], 'test'))->resolveOrFail();
    }

    public function testDuplicateKeysAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate shipping provider key "fake".');

        new ShippingProviderRegistry([new FakeShippingProvider(), new FakeShippingProvider()], 'test');
    }

    public function testABlankKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Shipping provider key must not be empty.');

        new ShippingProviderRegistry([new BlankKeyProvider()], 'test');
    }

    public function testTheFakeCarrierIsNeverProductionReady(): void
    {
        self::assertFalse((new FakeShippingProvider())->productionReady());
    }

    public function testTheFakeCarrierAnswersTheSameKeyWithTheSameParcel(): void
    {
        // The fake is asked twice and creates one parcel, because that is exactly what an adapter
        // honouring an idempotency key has to do. `acceptedCreateCount()` is the proof the
        // application would rely on; `createRequestCount()` shows the second call really was made
        // and answered from the memo rather than never happening.
        $provider = new FakeShippingProvider();
        $provider->queueCreation(ShipmentCreationOutcome::accepted('FAKE-SHIP-1', 'FAKE-TRK-1'));

        $first = $provider->create($this->instruction('shipment-EOA-20260928-ABCDEF123456'));
        $second = $provider->create($this->instruction('shipment-EOA-20260928-ABCDEF123456'));

        self::assertSame('FAKE-SHIP-1', $first->providerReference());
        self::assertSame('FAKE-TRK-1', $second->trackingNumber());
        self::assertSame(2, $provider->createRequestCount());
        self::assertSame(1, $provider->acceptedCreateCount());
    }

    public function testARefusedParcelIsNotRememberedBecauseNothingWasTaken(): void
    {
        // A carrier that refused took nothing, so the same key presented again is a fresh attempt
        // rather than the same refusal forever. This is what lets a retry succeed.
        $provider = new FakeShippingProvider();
        $provider->queueCreation(ShipmentCreationOutcome::refused(SanitizedFailure::fromProvider('service_unavailable', 'busy', null)));
        $provider->queueCreation(ShipmentCreationOutcome::accepted('FAKE-SHIP-AFTER-RETRY', 'FAKE-TRK-R'));

        $first = $provider->create($this->instruction('key-1'));
        $second = $provider->create($this->instruction('key-1'));

        self::assertFalse($first->isAccepted());
        self::assertTrue($second->isAccepted());
        self::assertSame('FAKE-SHIP-AFTER-RETRY', $second->providerReference());
        self::assertSame(2, $provider->createRequestCount());
        self::assertSame(1, $provider->acceptedCreateCount());
    }

    public function testADifferentKeyIsADifferentParcel(): void
    {
        $provider = new FakeShippingProvider();
        $provider->queueCreation(ShipmentCreationOutcome::accepted('FAKE-SHIP-1'));
        $provider->queueCreation(ShipmentCreationOutcome::accepted('FAKE-SHIP-2'));

        $provider->create($this->instruction('key-1'));
        $provider->create($this->instruction('key-2'));

        self::assertSame(2, $provider->createRequestCount());
        self::assertSame(2, $provider->acceptedCreateCount());
    }

    public function testTheFakeCarrierRefusesToInventAParcelItWasNeverToldToTake(): void
    {
        $this->expectException(\OutOfBoundsException::class);

        (new FakeShippingProvider())->create($this->instruction('key-1'));
    }

    public function testTheFakeCarrierReportsAndCancelsWhatItWasToldAbout(): void
    {
        $provider = new FakeShippingProvider();
        $provider->queueStatus(ShipmentStatusReport::reporting(ShipmentState::InTransit, 'FAKE-TRK-1', 'Yola çıktı'));
        $provider->queueCancellation(ShipmentCancellationOutcome::cancelled());
        $provider->queueLabel(new ShipmentLabel('FAKE-TRK-1', 'https://carrier.test/label/1.pdf'));

        self::assertSame(ShipmentState::InTransit, $provider->status(new ShipmentStatusRequest('fake', 'FAKE-SHIP-1'))->state());
        self::assertTrue($provider->cancel(new ShipmentCancellationInstruction('fake', 'FAKE-SHIP-1', 'cancel-key', 'Müşteri vazgeçti'))->isCancelled());
        self::assertSame('https://carrier.test/label/1.pdf', $provider->label(new ShipmentLabelRequest('fake', 'FAKE-SHIP-1'))?->documentUrl());
    }

    public function testACarrierWithoutLabelsAnswersNullRatherThanAFakeDocument(): void
    {
        self::assertNull((new FakeShippingProvider())->label(new ShipmentLabelRequest('fake', 'FAKE-SHIP-1')));
    }

    public function testAQueuedFailureReachesTheCallerAlreadySanitized(): void
    {
        $provider = new FakeShippingProvider();
        $provider->queueCreation(ShipmentCreationOutcome::refused(SanitizedFailure::fromProvider('address_invalid', 'no such district 4111111111111111', null)));

        $outcome = $provider->create($this->instruction('key-1'));

        self::assertFalse($outcome->isAccepted());
        self::assertStringNotContainsString('4111111111111111', (string) $outcome->failure()?->message());
    }

    public function testNoCarrierMethodAcceptsACustomerSecret(): void
    {
        foreach ((new \ReflectionClass(ShippingProviderInterface::class))->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                self::assertDoesNotMatchRegularExpression('/password|secret|token|pan|cvc/i', $parameter->getName(), $method->getName());
            }
        }
    }

    public function testEveryCarrierMethodIsImplementedByTheFake(): void
    {
        $implemented = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(FakeShippingProvider::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        foreach ((new \ReflectionClass(ShippingProviderInterface::class))->getMethods() as $method) {
            self::assertContains($method->getName(), $implemented, $method->getName());
        }
    }

    private function instruction(string $idempotencyKey): ShipmentCreationInstruction
    {
        return new ShipmentCreationInstruction(
            'fake',
            $idempotencyKey,
            'EOA-20260928-ABCDEF123456',
            'local_standard',
            'Yerel standart teslimat',
            new ShipmentAddress('Efe Yılmaz', '05000000000', 'Atatürk Cad. 1', null, 'Seyhan', 'Adana', '01000', 'TR'),
            [new ShipmentItem('BRK-1', 'Fren balatası', 2)],
            Money::ofMinor(30_000, 'TRY'),
        );
    }
}

final class BlankKeyProvider implements ShippingProviderInterface
{
    public function key(): string { return '   '; }
    public function displayName(): string { return 'Blank'; }
    public function productionReady(): bool { return false; }
    public function create(ShipmentCreationInstruction $instruction): ShipmentCreationOutcome { throw new \LogicException('unused'); }
    public function cancel(ShipmentCancellationInstruction $instruction): ShipmentCancellationOutcome { throw new \LogicException('unused'); }
    public function status(ShipmentStatusRequest $request): ShipmentStatusReport { throw new \LogicException('unused'); }
    public function label(ShipmentLabelRequest $request): ?ShipmentLabel { return null; }
}
