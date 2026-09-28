<?php

declare(strict_types=1);

namespace App\Module\Returns;

use App\Entity\Commerce\CustomerOrder;
use Psr\Clock\ClockInterface;

/**
 * Mints the number a customer quotes when asking about a return.
 *
 * The same shape as {@see \App\Module\Order\OrderNumberGenerator} on purpose: a support agent
 * reading "EOA-…" and "RET-…" can tell at a glance which they are looking at, and the random tail
 * means one customer's return number cannot be walked to another's.
 */
final readonly class ReturnNumberGenerator
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function generate(): string
    {
        return sprintf('RET-%s-%s', $this->clock->now()->format('Ymd'), strtoupper(bin2hex(random_bytes(6))));
    }
}
