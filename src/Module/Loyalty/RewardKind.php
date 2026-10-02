<?php

declare(strict_types=1);

namespace App\Module\Loyalty;

enum RewardKind: string
{
    case Earn = 'EARN';
    case Reversal = 'REVERSAL';
    case ManualAdjustment = 'MANUAL_ADJUSTMENT';
    case Spend = 'SPEND';
    case Expire = 'EXPIRE';

    public function label(): string
    {
        return match ($this) {
            self::Earn => 'Sipariş kazanımı',
            self::Reversal => 'İptal / iade',
            self::ManualAdjustment => 'Manuel düzeltme',
            self::Spend => 'Harcama',
            self::Expire => 'Süre sonu',
        };
    }
}
