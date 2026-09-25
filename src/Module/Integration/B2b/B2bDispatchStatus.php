<?php

namespace App\Module\Integration\B2b;

enum B2bDispatchStatus: string
{
    case Disabled = 'disabled';
    case Queued = 'queued';
    case Coalesced = 'coalesced';
    case Rejected = 'rejected';
}
