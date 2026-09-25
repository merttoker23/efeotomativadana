<?php

namespace App\Module\Integration\B2b;

interface B2bSyncServiceInterface
{
    public function request(B2bSyncMode $mode): B2bDispatchResult;
}
