<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Module\Payment\PaymentState;
use App\Module\Payment\PaymentStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentStateMachineTest extends TestCase
{
    /**
     * @return iterable<string, array{PaymentState, PaymentState}>
     */
    public static function allowedTransitions(): iterable
    {
        yield 'pending to requires action' => [PaymentState::Pending, PaymentState::RequiresAction];
        yield 'pending to succeeded' => [PaymentState::Pending, PaymentState::Succeeded];
        yield 'pending to failed' => [PaymentState::Pending, PaymentState::Failed];
        yield 'pending to cancelled' => [PaymentState::Pending, PaymentState::Cancelled];
        yield 'requires action to succeeded' => [PaymentState::RequiresAction, PaymentState::Succeeded];
        yield 'requires action to failed' => [PaymentState::RequiresAction, PaymentState::Failed];
        yield 'requires action to cancelled' => [PaymentState::RequiresAction, PaymentState::Cancelled];
        yield 'succeeded to partially refunded' => [PaymentState::Succeeded, PaymentState::PartiallyRefunded];
        yield 'succeeded to refunded' => [PaymentState::Succeeded, PaymentState::Refunded];
        yield 'partially refunded to refunded' => [PaymentState::PartiallyRefunded, PaymentState::Refunded];
    }

    #[DataProvider('allowedTransitions')]
    public function testDefinedTransitionsAreAccepted(PaymentState $from, PaymentState $to): void
    {
        self::assertTrue((new PaymentStateMachine())->canTransition($from, $to));
    }

    /**
     * @return iterable<string, array{PaymentState, PaymentState}>
     */
    public static function forbiddenTransitions(): iterable
    {
        yield 'pending cannot go back to nothing' => [PaymentState::Pending, PaymentState::Pending];
        yield 'pending cannot be refunded' => [PaymentState::Pending, PaymentState::Refunded];
        yield 'pending cannot be partially refunded' => [PaymentState::Pending, PaymentState::PartiallyRefunded];
        yield 'requires action cannot be refunded' => [PaymentState::RequiresAction, PaymentState::Refunded];
        yield 'succeeded cannot be retried' => [PaymentState::Succeeded, PaymentState::Pending];
        yield 'succeeded cannot fail after the fact' => [PaymentState::Succeeded, PaymentState::Failed];
        yield 'succeeded cannot be cancelled' => [PaymentState::Succeeded, PaymentState::Cancelled];
        yield 'partially refunded cannot succeed again' => [PaymentState::PartiallyRefunded, PaymentState::Succeeded];
        yield 'failed is terminal' => [PaymentState::Failed, PaymentState::Pending];
        yield 'failed cannot succeed later' => [PaymentState::Failed, PaymentState::Succeeded];
        yield 'cancelled is terminal' => [PaymentState::Cancelled, PaymentState::Succeeded];
        yield 'cancelled cannot be refunded' => [PaymentState::Cancelled, PaymentState::Refunded];
        yield 'refunded is terminal' => [PaymentState::Refunded, PaymentState::PartiallyRefunded];
        yield 'refunded cannot be captured' => [PaymentState::Refunded, PaymentState::Succeeded];
    }

    #[DataProvider('forbiddenTransitions')]
    public function testUndefinedTransitionsAreRejected(PaymentState $from, PaymentState $to): void
    {
        self::assertFalse((new PaymentStateMachine())->canTransition($from, $to));
    }

    public function testTransitionToTheSameStateIsNeverAllowed(): void
    {
        $machine = new PaymentStateMachine();

        foreach (PaymentState::cases() as $state) {
            self::assertFalse($machine->canTransition($state, $state), sprintf('%s must not allow a self transition.', $state->value));
        }
    }

    public function testAssertTransitionRejectsAnUndefinedTransition(): void
    {
        $machine = new PaymentStateMachine();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Payment cannot transition from succeeded to pending.');

        $machine->assertTransition(PaymentState::Succeeded, PaymentState::Pending);
    }

    public function testAssertTransitionReturnsTheTargetForADefinedTransition(): void
    {
        self::assertSame(
            PaymentState::RequiresAction,
            (new PaymentStateMachine())->assertTransition(PaymentState::Pending, PaymentState::RequiresAction),
        );
    }

    public function testOnlyAnUnencumberedCaptureCountsAsSuccessful(): void
    {
        self::assertTrue(PaymentState::Succeeded->isSuccessful());
        self::assertFalse(PaymentState::Pending->isSuccessful());
        self::assertFalse(PaymentState::RequiresAction->isSuccessful());
        self::assertFalse(PaymentState::PartiallyRefunded->isSuccessful());
        self::assertFalse(PaymentState::Failed->isSuccessful());
        self::assertFalse(PaymentState::Cancelled->isSuccessful());
        self::assertFalse(PaymentState::Refunded->isSuccessful());
    }

    public function testCapturedFundsSurviveAPartialRefund(): void
    {
        self::assertTrue(PaymentState::Succeeded->hasCapturedFunds());
        self::assertTrue(PaymentState::PartiallyRefunded->hasCapturedFunds());
        self::assertFalse(PaymentState::Refunded->hasCapturedFunds());
        self::assertFalse(PaymentState::Pending->hasCapturedFunds());
        self::assertFalse(PaymentState::RequiresAction->hasCapturedFunds());
        self::assertFalse(PaymentState::Failed->hasCapturedFunds());
        self::assertFalse(PaymentState::Cancelled->hasCapturedFunds());
    }

    public function testTerminalStatesCannotChangeAnyMore(): void
    {
        self::assertTrue(PaymentState::Refunded->isTerminal());
        self::assertTrue(PaymentState::Failed->isTerminal());
        self::assertTrue(PaymentState::Cancelled->isTerminal());
        self::assertFalse(PaymentState::PartiallyRefunded->isTerminal());
        self::assertFalse(PaymentState::Succeeded->isTerminal());
        self::assertFalse(PaymentState::Pending->isTerminal());
        self::assertFalse(PaymentState::RequiresAction->isTerminal());
    }

    public function testOnlyUncapturedAttemptsMayBeRetried(): void
    {
        self::assertTrue(PaymentState::Pending->canBeRetried());
        self::assertTrue(PaymentState::RequiresAction->canBeRetried());
        self::assertTrue(PaymentState::Failed->canBeRetried());
        self::assertFalse(PaymentState::Cancelled->canBeRetried());
        self::assertFalse(PaymentState::Succeeded->canBeRetried());
        self::assertFalse(PaymentState::PartiallyRefunded->canBeRetried());
        self::assertFalse(PaymentState::Refunded->canBeRetried());
    }

    public function testOnlyCapturedPaymentsMayBeRefunded(): void
    {
        self::assertTrue(PaymentState::Succeeded->isRefundable());
        self::assertTrue(PaymentState::PartiallyRefunded->isRefundable());
        self::assertFalse(PaymentState::Pending->isRefundable());
        self::assertFalse(PaymentState::RequiresAction->isRefundable());
        self::assertFalse(PaymentState::Failed->isRefundable());
        self::assertFalse(PaymentState::Cancelled->isRefundable());
        self::assertFalse(PaymentState::Refunded->isRefundable());
    }
}
