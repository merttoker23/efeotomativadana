<?php

declare(strict_types=1);

namespace App\Module\Returns;

/** One submitted return line: how many, and why. */
final class ReturnLineData
{
    public int $quantity = 0;
    public string $reason = '';
}
