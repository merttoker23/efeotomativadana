<?php

declare(strict_types=1);

namespace App\Module\Order;

use Psr\Clock\ClockInterface;

final readonly class OrderNumberGenerator
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function generate(): string
    {
        $date = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd');

        return sprintf('EOA-%s-%s', $date, strtoupper(bin2hex(random_bytes(6))));
    }
}
