<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Module\Payment\Gateway\CallbackAuthentication;
use App\Module\Payment\Gateway\GatewayInitiationInstruction;
use App\Module\Payment\Gateway\GatewayInitiationOutcome;
use App\Module\Payment\Gateway\GatewayRefundInstruction;
use App\Module\Payment\Gateway\GatewayRefundOutcome;
use App\Module\Payment\Gateway\IncomingPaymentCallback;
use App\Module\Payment\Gateway\PaymentGatewayInterface;
use App\Module\Payment\Gateway\RefundStatus;
use App\Module\Payment\PaymentState;
use App\Module\Payment\SanitizedFailure;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

final class PaymentGatewayContractTest extends TestCase
{
    public function testTheContractIsProviderNeutralAndTaggedForAutoconfiguration(): void
    {
        $attributes = (new \ReflectionClass(PaymentGatewayInterface::class))->getAttributes(AutoconfigureTag::class);

        self::assertCount(1, $attributes);
        self::assertSame(['app.payment_gateway'], $attributes[0]->getArguments());
    }

    public function testInitiationCarriesExactlyWhatAGatewayNeedsAndNothingSensitive(): void
    {
        $instruction = new GatewayInitiationInstruction(
            'EOA-20260925-ABCDEF123456',
            'attempt-1',
            'return-token-1',
            Money::ofMinor(45_000, 'TRY'),
            'https://store.test/odeme/sonuc/return-token-1',
            'https://store.test/odeme/iptal/return-token-1',
            'musteri@example.com',
            'tr',
        );

        self::assertSame(45_000, $instruction->amount()->minorAmount());
        self::assertSame('TRY', $instruction->amount()->currency());
        self::assertSame('attempt-1', $instruction->attemptSequence());
        self::assertSame('https://store.test/odeme/sonuc/return-token-1', $instruction->returnUrl());
        self::assertSame('https://store.test/odeme/iptal/return-token-1', $instruction->cancelUrl());
    }

    public function testInitiationInstructionRejectsANonPositiveAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->instruction(Money::ofMinor(0, 'TRY'));
    }

    public function testInitiationInstructionRejectsAnUnusableReturnUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GatewayInitiationInstruction('EOA-20260925-ABCDEF123456', 'attempt-1', 'token', Money::ofMinor(100, 'TRY'), 'javascript:alert(1)', 'https://store.test/x', 'm@example.com', 'tr');
    }

    public function testRedirectOutcomeCarriesAnAbsoluteHttpsUrl(): void
    {
        $outcome = GatewayInitiationOutcome::redirect('https://pay.test/hosted/abc', 'REF-1');

        self::assertTrue($outcome->requiresCustomerRedirect());
        self::assertSame('https://pay.test/hosted/abc', $outcome->redirectUrl());
        self::assertSame('REF-1', $outcome->providerReference());
        self::assertNull($outcome->failure());
    }

    public function testHostedFormOutcomeCarriesSanitizedFields(): void
    {
        $outcome = GatewayInitiationOutcome::hostedForm('https://pay.test/hosted/form', ['merchant_id' => 'M-1', 'token' => 'T-1'], 'REF-1');

        self::assertTrue($outcome->requiresCustomerRedirect());
        self::assertFalse($outcome->isRedirect());
        self::assertSame('https://pay.test/hosted/form', $outcome->hostedFormActionUrl());
        self::assertSame(['merchant_id' => 'M-1', 'token' => 'T-1'], $outcome->hostedFormFields());
    }

    public function testAHostedFormActionMustBeAnAbsoluteHttpsUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GatewayInitiationOutcome::hostedForm('http://pay.test/insecure', ['token' => 'T-1']);
    }

    public function testARedirectMustBeAnAbsoluteHttpsUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GatewayInitiationOutcome::redirect('javascript:alert(1)');
    }

    public function testAwaitingCallbackOutcomeNeedsNoCustomerRedirect(): void
    {
        $outcome = GatewayInitiationOutcome::awaitingCallback('REF-1');

        self::assertFalse($outcome->requiresCustomerRedirect());
        self::assertTrue($outcome->isAwaitingCallback());
        self::assertSame(PaymentState::Pending, $outcome->state());
    }

    public function testFailedOutcomeCarriesSanitizedMetadata(): void
    {
        $failure = SanitizedFailure::fromProvider('card_declined', 'Declined 4111111111111111', null);
        $outcome = GatewayInitiationOutcome::failed($failure);

        self::assertFalse($outcome->requiresCustomerRedirect());
        self::assertSame('card_declined', $outcome->failure()->code());
        self::assertStringNotContainsString('4111111111111111', (string) $outcome->failure()->message());
    }

    public function testCallbackAuthenticationRejectsAnUnverifiedCallbackWithAReason(): void
    {
        self::assertTrue(CallbackAuthentication::authentic()->verified());
        self::assertNull(CallbackAuthentication::authentic()->reason());

        $rejected = CallbackAuthentication::rejected('signature_mismatch');
        self::assertFalse($rejected->verified());
        self::assertSame('signature_mismatch', $rejected->reason());
    }

    public function testRejectedCallbackCarriesNoProviderClaims(): void
    {
        $rejected = CallbackAuthentication::rejected('signature_mismatch');

        self::assertNull($rejected->providerReference());
        self::assertNull($rejected->outcome());
        self::assertNull($rejected->capturedAmount());
    }

    public function testCallbackAuthenticationAlwaysCarriesTheRawProviderReference(): void
    {
        self::assertSame('REF-9', CallbackAuthentication::authentic('REF-9')->providerReference());
        self::assertNull(CallbackAuthentication::authentic()->providerReference());
    }

    public function testIncomingCallbackPreservesTheRawBodyForSignatureVerification(): void
    {
        $callback = new IncomingPaymentCallback('{"a":1}&b=2', ['X-Signature' => 'abc'], ['b' => '2']);

        self::assertSame('{"a":1}&b=2', $callback->rawBody());
        self::assertSame('abc', $callback->header('X-Signature'));
        self::assertSame('2', $callback->queryParameter('b'));
    }

    public function testIncomingCallbackHeaderLookupIsCaseInsensitive(): void
    {
        $callback = new IncomingPaymentCallback('', ['X-Signature' => 'abc'], []);

        self::assertSame('abc', $callback->header('x-signature'));
        self::assertNull($callback->header('x-missing'));
    }

    public function testRefundOutcomeReportsTheProviderReferenceAndStatus(): void
    {
        $outcome = GatewayRefundOutcome::completed('R-1');
        self::assertSame(RefundStatus::Completed, $outcome->status());
        self::assertSame('R-1', $outcome->providerReference());
        self::assertNull($outcome->failure());

        $failed = GatewayRefundOutcome::failed(SanitizedFailure::fromProvider('insufficient_funds', 'no money', null));
        self::assertSame(RefundStatus::Failed, $failed->status());
        self::assertSame('insufficient_funds', $failed->failure()?->code());
    }

    public function testRefundInstructionCarriesTheIdempotencyKeyAndReason(): void
    {
        $instruction = new GatewayRefundInstruction('R-1', Money::ofMinor(1_000, 'TRY'), 'refund-key-1', 'customer request');

        self::assertSame('R-1', $instruction->providerReference());
        self::assertSame(1_000, $instruction->amount()->minorAmount());
        self::assertSame('refund-key-1', $instruction->idempotencyKey());
        self::assertSame('customer request', $instruction->reason());
    }

    private function instruction(Money $amount): GatewayInitiationInstruction
    {
        return new GatewayInitiationInstruction('EOA-20260925-ABCDEF123456', 'attempt-1', 'token', $amount, 'https://store.test/a', 'https://store.test/b', 'm@example.com', 'tr');
    }
}
