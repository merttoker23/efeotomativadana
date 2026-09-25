<?php

namespace App\Module\Integration\B2b;

final readonly class B2bSyncCheckpoint
{
    public function __construct(public int $recordOffset = 0)
    {
        if ($recordOffset < 0) {
            throw new \InvalidArgumentException('B2B sync checkpoint cannot be negative.');
        }
    }
}
