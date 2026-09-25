<?php

namespace App\Module\Integration\B2b;

use App\Entity\Integration\B2bSyncRun;

final readonly class B2bRunCreationResult
{
    public function __construct(public B2bSyncRun $run, public bool $created)
    {
    }
}
