<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shipping;

use App\Module\Shipping\ShipmentState;
use App\Module\Shipping\ShipmentStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShipmentStateMachineTest extends TestCase
{
    /**
     * @return iterable<string, array{ShipmentState, ShipmentState}>
     */
    public static function allowedTransitions(): iterable
    {
        yield 'pending to ready' => [ShipmentState::Pending, ShipmentState::Ready];
        yield 'pending straight to in transit' => [ShipmentState::Pending, ShipmentState::InTransit];
        yield 'pending to failed' => [ShipmentState::Pending, ShipmentState::Failed];
        yield 'pending to cancelled' => [ShipmentState::Pending, ShipmentState::Cancelled];
        yield 'ready to in transit' => [ShipmentState::Ready, ShipmentState::InTransit];
        yield 'ready to delivered' => [ShipmentState::Ready, ShipmentState::Delivered];
        yield 'ready to failed' => [ShipmentState::Ready, ShipmentState::Failed];
        yield 'ready to cancelled' => [ShipmentState::Ready, ShipmentState::Cancelled];
        yield 'in transit to delivered' => [ShipmentState::InTransit, ShipmentState::Delivered];
        yield 'in transit to failed' => [ShipmentState::InTransit, ShipmentState::Failed];
        yield 'a failed creation may be retried' => [ShipmentState::Failed, ShipmentState::Ready];
        yield 'a failed creation may be sent back to pending' => [ShipmentState::Failed, ShipmentState::Pending];
        yield 'a failed shipment may be given up on' => [ShipmentState::Failed, ShipmentState::Cancelled];
    }

    #[DataProvider('allowedTransitions')]
    public function testDefinedTransitionsAreAccepted(ShipmentState $from, ShipmentState $to): void
    {
        self::assertTrue((new ShipmentStateMachine())->canTransition($from, $to));
    }

    /**
     * @return iterable<string, array{ShipmentState, ShipmentState}>
     */
    public static function forbiddenTransitions(): iterable
    {
        yield 'delivered cannot travel again' => [ShipmentState::Delivered, ShipmentState::InTransit];
        yield 'delivered cannot be un-delivered' => [ShipmentState::Delivered, ShipmentState::Ready];
        yield 'delivered cannot be cancelled' => [ShipmentState::Delivered, ShipmentState::Cancelled];
        yield 'cancelled cannot be resurrected' => [ShipmentState::Cancelled, ShipmentState::Ready];
        yield 'cancelled cannot be delivered' => [ShipmentState::Cancelled, ShipmentState::Delivered];
        yield 'cancelled cannot be failed' => [ShipmentState::Cancelled, ShipmentState::Failed];
        // A parcel already on the road cannot be recalled by an operator; that is a return,
        // which is a different workflow with its own reason and its own audit.
        yield 'in transit cannot be cancelled' => [ShipmentState::InTransit, ShipmentState::Cancelled];
        yield 'in transit cannot go back to ready' => [ShipmentState::InTransit, ShipmentState::Ready];
        yield 'ready cannot be re-created' => [ShipmentState::Ready, ShipmentState::Pending];
        yield 'failed cannot jump to delivered' => [ShipmentState::Failed, ShipmentState::Delivered];
        yield 'failed cannot jump to in transit' => [ShipmentState::Failed, ShipmentState::InTransit];
    }

    #[DataProvider('forbiddenTransitions')]
    public function testUndefinedTransitionsAreRejected(ShipmentState $from, ShipmentState $to): void
    {
        self::assertFalse((new ShipmentStateMachine())->canTransition($from, $to));
    }

    public function testTransitionToTheSameStateIsNeverAllowed(): void
    {
        $machine = new ShipmentStateMachine();

        foreach (ShipmentState::cases() as $state) {
            self::assertFalse($machine->canTransition($state, $state), sprintf('%s must not allow a self transition.', $state->value));
        }
    }

    public function testAssertTransitionRejectsAnUndefinedTransition(): void
    {
        $machine = new ShipmentStateMachine();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Shipment cannot transition from delivered to in_transit.');

        $machine->assertTransition(ShipmentState::Delivered, ShipmentState::InTransit);
    }

    public function testAssertTransitionReturnsTheTargetForADefinedTransition(): void
    {
        self::assertSame(
            ShipmentState::Delivered,
            (new ShipmentStateMachine())->assertTransition(ShipmentState::InTransit, ShipmentState::Delivered),
        );
    }

    public function testDeliveredAndCancelledAreTerminalButAFailureIsNot(): void
    {
        self::assertTrue(ShipmentState::Delivered->isTerminal());
        self::assertTrue(ShipmentState::Cancelled->isTerminal());
        self::assertFalse(ShipmentState::Pending->isTerminal());
        self::assertFalse(ShipmentState::Ready->isTerminal());
        self::assertFalse(ShipmentState::InTransit->isTerminal());
        // A failed creation is not terminal: the very same shipment is retried, which is what
        // makes the provider idempotency key worth carrying.
        self::assertFalse(ShipmentState::Failed->isTerminal());
    }

    public function testOnlyAShipmentThatHasNotLeftTheBuildingMayBeCancelledByHand(): void
    {
        self::assertTrue(ShipmentState::Pending->isCancellable());
        self::assertTrue(ShipmentState::Ready->isCancellable());
        self::assertTrue(ShipmentState::Failed->isCancellable());
        self::assertFalse(ShipmentState::InTransit->isCancellable());
        self::assertFalse(ShipmentState::Delivered->isCancellable());
        self::assertFalse(ShipmentState::Cancelled->isCancellable());
    }

    public function testOnlyDeliveredCountsAsFulfilled(): void
    {
        self::assertTrue(ShipmentState::Delivered->isFulfilled());
        self::assertFalse(ShipmentState::InTransit->isFulfilled());
        self::assertFalse(ShipmentState::Ready->isFulfilled());
        self::assertFalse(ShipmentState::Pending->isFulfilled());
        self::assertFalse(ShipmentState::Failed->isFulfilled());
        self::assertFalse(ShipmentState::Cancelled->isFulfilled());
    }
}
