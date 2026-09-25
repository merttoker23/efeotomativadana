<?php

namespace App\Message;

use App\Module\Integration\B2b\B2bSyncMode;

final readonly class RunB2bSync
{
    public function __construct(public int $runId, public B2bSyncMode $mode)
    {
        if ($runId < 1) {
            throw new \InvalidArgumentException('B2B message run ID must be positive.');
        }
    }
}
