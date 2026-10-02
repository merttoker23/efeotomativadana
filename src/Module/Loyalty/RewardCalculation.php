<?php

declare(strict_types=1);

namespace App\Module\Loyalty;

/** One point per whole currency unit of percentage reward; always round down. */
final readonly class RewardCalculation
{
    public function earned(int $eligibleMinor, int $percentage): int
    {
        if ($eligibleMinor < 0 || $percentage < 0 || $percentage > 100) {
            throw new \InvalidArgumentException('Reward amount must be nonnegative and percentage between 0 and 100.');
        }
        // Divide first so even a BIGINT amount cannot overflow when multiplied by the rate.
        return intdiv($eligibleMinor, 10_000) * $percentage + intdiv(($eligibleMinor % 10_000) * $percentage, 10_000);
    }

    public function reversed(int $eligibleMinor, int $percentage, int $refundedMinor): int
    {
        if ($refundedMinor < 0) {
            throw new \InvalidArgumentException('Refunded reward amount cannot be negative.');
        }
        return $this->earned(min($eligibleMinor, $refundedMinor), $percentage);
    }
}
