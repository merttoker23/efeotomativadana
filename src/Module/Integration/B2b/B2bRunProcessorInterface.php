<?php

namespace App\Module\Integration\B2b;

interface B2bRunProcessorInterface
{
    public function process(int $runId): void;
}
