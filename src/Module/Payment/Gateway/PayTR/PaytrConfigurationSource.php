<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway\PayTR;

interface PaytrConfigurationSource
{
    public function current(): PaytrConfiguration;
}
