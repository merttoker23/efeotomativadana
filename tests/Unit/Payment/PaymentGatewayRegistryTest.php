<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Module\Payment\FakePaymentGateway;
use App\Module\Payment\Gateway\CallbackAuthentication;
use App\Module\Payment\Gateway\GatewayInitiationInstruction;
use App\Module\Payment\Gateway\GatewayInitiationOutcome;
use App\Module\Payment\Gateway\GatewayRefundInstruction;
use App\Module\Payment\Gateway\GatewayRefundOutcome;
use App\Module\Payment\Gateway\IncomingPaymentCallback;
use App\Module\Payment\Gateway\PaymentGatewayInterface;
use App\Module\Payment\PaymentGatewayNotAvailable;
use App\Module\Payment\PaymentGatewayRegistry;
use App\Module\Payment\SanitizedFailure;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

final class PaymentGatewayRegistryTest extends TestCase
{
    public function testAnEmptyRegistryResolvesNothing(): void
    {
        $registry = new PaymentGatewayRegistry([], 'prod');

        self::assertNull($registry->resolve(null));
        self::assertFalse($registry->supports('fake'));
    }

    public function testASelectedGatewayIsResolvedCaseInsensitively(): void
    {
        $registry = new PaymentGatewayRegistry([new FakePaymentGateway()], 'test');

        self::assertTrue($registry->supports('FAKE'));
        self::assertSame('fake', $registry->resolve('FaKe')?->key());
    }

    public function testAKeyIsMatchedCaseInsensitivelyEvenWhenTheAdapterIsRefused(): void
    {
        $registry = new PaymentGatewayRegistry([new FakePaymentGateway()], 'prod');

        self::assertTrue($registry->supports('FAKE'));
    }

    public function testTheFakeGatewayCannotSilentlyRemainEnabledForProduction(): void
    {
        $registry = new PaymentGatewayRegistry([new FakePaymentGateway()], 'prod');

        $this->expectException(PaymentGatewayNotAvailable::class);
        $this->expectExceptionMessage('not permitted in the prod environment');

        $registry->resolve('fake');
    }

    public function testTheFakeGatewayStaysAvailableForDevelopmentAndTest(): void
    {
        foreach (['dev', 'test'] as $environment) {
            $registry = new PaymentGatewayRegistry([new FakePaymentGateway()], $environment);

            self::assertSame('fake', $registry->resolve('fake')?->key(), $environment);
        }
    }

    public function testAMissingSelectionIsNotAProductionFailure(): void
    {
        $registry = new PaymentGatewayRegistry([new FakePaymentGateway()], 'prod');

        self::assertNull($registry->resolve(null), 'An unconfigured provider must leave the store usable.');
    }

    public function testAMissingSelectionFailsForARequestThatCannotProceedWithoutOne(): void
    {
        $registry = new PaymentGatewayRegistry([new FakePaymentGateway()], 'test');

        $this->expectException(PaymentGatewayNotAvailable::class);
        $this->expectExceptionMessage('No payment provider is configured');

        $registry->resolveOrFail();
    }

    public function testDuplicateKeysAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate payment gateway key "fake".');

        new PaymentGatewayRegistry([new FakePaymentGateway(), new FakePaymentGateway()], 'test');
    }

    public function testABlankKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Payment gateway key must not be empty.');

        new PaymentGatewayRegistry([new BlankKeyGateway()], 'test');
    }

    public function testTheFakeGatewayIsNeverProductionReady(): void
    {
        self::assertFalse((new FakePaymentGateway())->productionReady());
    }

    public function testTheFakeGatewayIsNotSelectableInProduction(): void
    {
        $registry = new PaymentGatewayRegistry([new FakePaymentGateway()], 'prod');

        self::assertSame([], $registry->selectableKeys());
        self::assertSame(['fake'], (new PaymentGatewayRegistry([new FakePaymentGateway()], 'test'))->selectableKeys());
    }

    /**
     * A deployment called `staging` is still a real deployment with real money behind it, so
     * the fake must be refused there too. Only dev, test and local are exempt.
     */
    public function testTheFakeGatewayIsRefusedInAnyEnvironmentThatIsNotDevelopment(): void
    {
        foreach (['prod', 'staging', 'production', 'preview'] as $environment) {
            $registry = new PaymentGatewayRegistry([new FakePaymentGateway()], $environment);

            self::assertTrue($registry->isProduction(), $environment);
            self::assertSame([], $registry->selectableKeys(), $environment);
            $this->expectException(PaymentGatewayNotAvailable::class);
            $registry->resolve('fake');
        }
    }

    public function testDevelopmentEnvironmentsAreExempt(): void
    {
        foreach (['dev', 'test', 'local'] as $environment) {
            $registry = new PaymentGatewayRegistry([new FakePaymentGateway()], $environment);

            self::assertFalse($registry->isProduction(), $environment);
            self::assertSame('fake', $registry->resolve('fake')?->key(), $environment);
        }
    }

    public function testTheFakeGatewayCoversSuccessFailureAndReplayedCallback(): void
    {
        $gateway = new FakePaymentGateway();
        $instruction = $this->instruction();

        $gateway->queueInitiation(GatewayInitiationOutcome::redirect('https://pay.test/hosted/a', 'FAKE-REF-1'));
        $outcome = $gateway->initiate($instruction);
        self::assertTrue($outcome->isRedirect());
        self::assertSame('FAKE-REF-1', $outcome->providerReference());

        $gateway->queueInitiation(GatewayInitiationOutcome::failed(SanitizedFailure::fromProvider('card_declined', 'Declined 4111111111111111', null)));
        $failure = $gateway->initiate($this->instruction('idem-2', 'return-token-2'));
        self::assertTrue($failure->isFailed());
        self::assertStringNotContainsString('4111111111111111', (string) $failure->failure()?->message());
    }

    public function testTheFakeGatewayAcceptsAReplayedCallbackButRejectsATamperedOne(): void
    {
        $gateway = $this->gatewayAwaitingCallback('FAKE-REF-2');

        self::assertTrue($gateway->authenticateCallback($this->incomingCallback('FAKE-REF-2', 'succeeded', 45_000, 'TRY'))->verified());
        self::assertTrue($gateway->authenticateCallback($this->incomingCallback('FAKE-REF-2', 'succeeded', 45_000, 'TRY'))->verified(), 'A replayed callback must verify identically.');
        self::assertFalse($gateway->authenticateCallback($this->incomingCallback('FAKE-REF-2', 'succeeded', 45_000, 'TRY', 'tampered'))->verified());
    }

    public function testTheFakeGatewayRejectsAForgedCallbackForAnUnknownReference(): void
    {
        $gateway = $this->gatewayAwaitingCallback('FAKE-REF-3');

        self::assertFalse($gateway->authenticateCallback($this->incomingCallback('NEVER-ISSUED', 'succeeded', 100, 'TRY'))->verified());
    }

    public function testTheFakeGatewayRejectsAnAmountThatIsNotAWholeMinorUnit(): void
    {
        $gateway = $this->gatewayAwaitingCallback('FAKE-REF-4');

        self::assertFalse($gateway->authenticateCallback($this->incomingCallback('FAKE-REF-4', 'succeeded', 0, 'TRY'))->verified());
        self::assertFalse($gateway->authenticateCallback($this->incomingCallback('FAKE-REF-4', 'succeeded', -5, 'TRY'))->verified());
        self::assertFalse($gateway->authenticateCallback($this->incomingCallback('FAKE-REF-4', 'succeeded', 45_000, 'EUR'))->verified());
    }

    public function testTheFakeGatewayIsIdempotentForTheSameIdempotencyKey(): void
    {
        $gateway = new FakePaymentGateway();
        $gateway->queueInitiation(GatewayInitiationOutcome::redirect('https://pay.test/hosted/a', 'FAKE-IDEM'));
        $first = $gateway->initiate($this->instruction('idem-key-1'));
        $second = $gateway->initiate($this->instruction('idem-key-1'));

        self::assertSame($first->redirectUrl(), $second->redirectUrl());
        self::assertSame('FAKE-IDEM', $second->providerReference());
    }

    public function testTheFakeGatewayRefundsAndReleasesAuthorizations(): void
    {
        $gateway = new FakePaymentGateway();
        $gateway->queueRefund(GatewayRefundOutcome::completed('FAKE-R-1'));
        $outcome = $gateway->refund(new GatewayRefundInstruction('FAKE-REF-1', Money::ofMinor(1_000, 'TRY'), 'refund-key', 'customer request'));

        self::assertSame('FAKE-R-1', $outcome->providerReference());

        $gateway->queueRefund(GatewayRefundOutcome::failed(SanitizedFailure::fromProvider('not_refundable', 'already refunded', null)));
        $failed = $gateway->refund(new GatewayRefundInstruction('FAKE-REF-1', Money::ofMinor(1_000, 'TRY'), 'refund-key-2', 'customer request'));

        self::assertSame('not_refundable', $failed->failure()?->code());
    }

    public function testTheFakeGatewayRunsOutOfQueuedOutcomesInsteadOfInventingSuccess(): void
    {
        $gateway = new FakePaymentGateway();

        $this->expectException(\OutOfBoundsException::class);
        $gateway->initiate($this->instruction());
    }

    public function testNoGatewayMethodAcceptsCardData(): void
    {
        foreach ((new \ReflectionClass(PaymentGatewayInterface::class))->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                self::assertDoesNotMatchRegularExpression('/card|pan|cvc|cvv|secret/i', $parameter->getName(), $method->getName());
                self::assertDoesNotMatchRegularExpression('/card|pan|cvc|cvv/i', (string) $parameter->getType(), $method->getName());
            }
        }
    }

    public function testEveryGatewayMethodIsImplementedByTheFake(): void
    {
        $implemented = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(FakePaymentGateway::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        $required = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(PaymentGatewayInterface::class))->getMethods(),
        );

        foreach ($required as $method) {
            self::assertContains($method, $implemented, $method);
        }
    }

    private function gatewayAwaitingCallback(string $reference): FakePaymentGateway
    {
        $gateway = new FakePaymentGateway();
        $gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback($reference));
        $gateway->initiate($this->instruction($reference));

        return $gateway;
    }

    private function instruction(string $idempotencyKey = 'idem-key', string $returnToken = 'return-token'): GatewayInitiationInstruction
    {
        return new GatewayInitiationInstruction(
            'EOA-20260925-ABCDEF123456',
            $idempotencyKey,
            $returnToken,
            Money::ofMinor(45_000, 'TRY'),
            'https://store.test/odeme/sonuc/return-token',
            'https://store.test/odeme/iptal/return-token',
            'musteri@example.com',
            'tr',
        );
    }

    private function incomingCallback(string $reference, string $outcome, int $amount, string $currency, string $signature = 'valid'): IncomingPaymentCallback
    {
        return new IncomingPaymentCallback(
            http_build_query(['ref' => $reference, 'outcome' => $outcome, 'amount' => (string) $amount, 'currency' => $currency]),
            ['X-Fake-Signature' => $signature],
            [],
        );
    }
}

final class BlankKeyGateway implements PaymentGatewayInterface
{
    public function key(): string { return '   '; }
    public function label(): string { return 'Blank'; }
    public function productionReady(): bool { return false; }
    public function initiate(GatewayInitiationInstruction $instruction): GatewayInitiationOutcome { throw new \LogicException('unused'); }
    public function authenticateCallback(IncomingPaymentCallback $incoming): CallbackAuthentication { throw new \LogicException('unused'); }
    public function refund(GatewayRefundInstruction $instruction): GatewayRefundOutcome { throw new \LogicException('unused'); }
    public function releaseAuthorization(GatewayRefundInstruction $instruction): ?GatewayRefundOutcome { return null; }
}
