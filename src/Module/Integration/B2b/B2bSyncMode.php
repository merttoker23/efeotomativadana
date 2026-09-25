<?php

namespace App\Module\Integration\B2b;

enum B2bSyncMode: string
{
    case Full = 'full';
    case Daily = 'daily';
}
