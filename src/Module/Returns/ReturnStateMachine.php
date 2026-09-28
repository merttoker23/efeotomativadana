<?php

declare(strict_types=1);

namespace App\Module\Returns;

final readonly class ReturnStateMachine
{
    public function canTransition(ReturnState $from, ReturnState $to): bool
    {
        return in_array($to, $this->allowedTargets($from), true);
    }

    public function assertTransition(ReturnState $from, ReturnState $to): ReturnState
    {
        if (!$this->canTransition($from, $to)) {
            throw new \DomainException(sprintf('Return cannot transition from %s to %s.', $from->value, $to->value));
        }

        return $to;
    }

    /** @return list<ReturnState> */
    private function allowedTargets(ReturnState $from): array
    {
        return match ($from) {
            // Withdrawal is reachable from `requested` and from `approved`, and nowhere else.
            // Before the store answers, and after it says yes but before the goods arrive, the
            // customer may still change their mind. Once the goods are in, or the store has said
            // no, the conversation is over and only the store moves it.
            ReturnState::Requested => [ReturnState::Approved, ReturnState::Rejected, ReturnState::Withdrawn],
            ReturnState::Approved => [ReturnState::Received, ReturnState::Withdrawn],
            ReturnState::Received => [ReturnState::Refunded],
            ReturnState::Refunded, ReturnState::Rejected, ReturnState::Withdrawn => [],
        };
    }
}
