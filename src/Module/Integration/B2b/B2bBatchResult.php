<?php

namespace App\Module\Integration\B2b;

final readonly class B2bBatchResult
{
    /** @param list<B2bItemError> $errors */
    public function __construct(
        public B2bSyncCounters $counters,
        public array $errors = [],
    ) {
    }
}
