<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Payment;

use App\Module\Payment\Gateway\PayTR\PaymentSecretCipher;
use Doctrine\DBAL\Connection;

final class PaytrConfigurationFixture
{
    public const string KEY = 'test-merchant-key';
    public const string SALT = 'test-merchant-salt';

    public static function configure(Connection $connection, PaymentSecretCipher $cipher): void
    {
        $connection->update('payment_configuration', [
            'merchant_id' => '123456',
            'merchant_key_encrypted' => $cipher->encrypt(self::KEY, 'key'),
            'merchant_salt_encrypted' => $cipher->encrypt(self::SALT, 'salt'),
            'test_mode' => 1,
        ], ['id' => 1]);
    }
}
