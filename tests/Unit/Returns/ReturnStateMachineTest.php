<?php

declare(strict_types=1);

namespace App\Tests\Unit\Returns;

use App\Module\Returns\ReturnState;
use App\Module\Returns\ReturnStateMachine;
use PHPUnit\Framework\TestCase;

final class ReturnStateMachineTest extends TestCase
{
    public function testTheLegalTransitionsAreExactlyTheAgreedOnes(): void
    {
        $machine = new ReturnStateMachine();

        // The customer may withdraw only while the store has not yet decided.
        self::assertTrue($machine->canTransition(ReturnState::Requested, ReturnState::Approved));
        self::assertTrue($machine->canTransition(ReturnState::Requested, ReturnState::Rejected));
        self::assertTrue($machine->canTransition(ReturnState::Requested, ReturnState::Withdrawn));

        self::assertTrue($machine->canTransition(ReturnState::Approved, ReturnState::Received));
        self::assertTrue($machine->canTransition(ReturnState::Approved, ReturnState::Withdrawn));
        self::assertTrue($machine->canTransition(ReturnState::Received, ReturnState::Refunded));

        self::assertFalse($machine->canTransition(ReturnState::Requested, ReturnState::Received));
        self::assertFalse($machine->canTransition(ReturnState::Requested, ReturnState::Refunded));
        self::assertFalse($machine->canTransition(ReturnState::Approved, ReturnState::Rejected));
        self::assertFalse($machine->canTransition(ReturnState::Approved, ReturnState::Refunded));
        self::assertFalse($machine->canTransition(ReturnState::Received, ReturnState::Approved));

        foreach ([ReturnState::Rejected, ReturnState::Withdrawn, ReturnState::Refunded] as $terminal) {
            self::assertTrue($terminal->isTerminal());
            foreach (ReturnState::cases() as $target) {
                self::assertFalse($machine->canTransition($terminal, $target), sprintf('%s -> %s must be refused.', $terminal->value, $target->value));
            }
        }
    }

    public function testAnIllegalTransitionIsRefusedWithBothStatesNamed(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Return cannot transition from requested to refunded.');

        (new ReturnStateMachine())->assertTransition(ReturnState::Requested, ReturnState::Refunded);
    }

    public function testARepeatedTransitionIsRefusedSoAReplayedEventCannotReopenAClosedRequest(): void
    {
        $machine = new ReturnStateMachine();

        self::assertFalse($machine->canTransition(ReturnState::Approved, ReturnState::Approved));
        self::assertFalse($machine->canTransition(ReturnState::Rejected, ReturnState::Rejected));
    }

    public function testOnlyRequestedIsOpen(): void
    {
        self::assertTrue(ReturnState::Requested->isOpen());
        self::assertFalse(ReturnState::Approved->isOpen());
        self::assertFalse(ReturnState::Received->isOpen());
        self::assertFalse(ReturnState::Refunded->isOpen());
        self::assertFalse(ReturnState::Rejected->isOpen());
        self::assertFalse(ReturnState::Withdrawn->isOpen());
    }

    public function testOnlyReceivedAwaitsARefund(): void
    {
        self::assertTrue(ReturnState::Received->awaitsRefund());
        self::assertFalse(ReturnState::Approved->awaitsRefund());
        self::assertFalse(ReturnState::Requested->awaitsRefund());
        self::assertFalse(ReturnState::Refunded->awaitsRefund());
    }
}
