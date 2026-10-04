<?php

declare(strict_types=1);

namespace App\Module\Order;

final class OrderNote
{
    public const int MAX_LENGTH = 1000;

    public static function normalize(?string $note): ?string
    {
        $note = trim($note ?? '');
        if ('' === $note) {
            return null;
        }
        if (mb_strlen($note) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('Sipariş notu en fazla 1000 karakter olabilir.');
        }
        if (strip_tags($note) !== $note) {
            throw new \InvalidArgumentException('Sipariş notu yalnızca düz metin içerebilir; HTML kullanmayın.');
        }

        return $note;
    }
}
