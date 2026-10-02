<?php

declare(strict_types=1);

namespace App\Tests\Unit\Loyalty;

use App\Module\Loyalty\RewardCalculation;
use PHPUnit\Framework\TestCase;

final class RewardCalculationTest extends TestCase
{
    public function testWholePointsRoundDownAndNeverUseShippingOrFloatingPoint(): void
    {
        $math = new RewardCalculation();
        self::assertSame(3, $math->earned(30_099, 1));
        self::assertSame(0, $math->earned(9_999, 1));
        self::assertSame(1, $math->earned(10_000, 1));
        self::assertSame(0, $math->earned(10_000, 0));
        self::assertSame(92233720368547758, $math->earned(PHP_INT_MAX, 100));
    }

    public function testCumulativeRefundRoundingEventuallyReversesAllPoints(): void
    {
        $math = new RewardCalculation();
        self::assertSame(0, $math->reversed(30_099, 1, 9_999));
        self::assertSame(1, $math->reversed(30_099, 1, 10_000));
        self::assertSame(3, $math->reversed(30_099, 1, 30_099));
        self::assertSame(3, $math->reversed(30_099, 1, 40_000));
    }

    public function testExtremePercentageIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RewardCalculation())->earned(100, 101);
    }

    public function testNegativeAmountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RewardCalculation())->earned(-1, 10);
    }

    public function testNegativeRefundIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RewardCalculation())->reversed(100, 10, -1);
    }
}
