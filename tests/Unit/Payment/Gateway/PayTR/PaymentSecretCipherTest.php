<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Gateway\PayTR;

use App\Module\Payment\Gateway\PayTR\PaymentSecretCipher;
use PHPUnit\Framework\TestCase;

final class PaymentSecretCipherTest extends TestCase
{
    public function testSecretsUseRandomizedAuthenticatedEncryption(): void
    {
        $cipher = new PaymentSecretCipher(str_repeat('a', 64));
        $first = $cipher->encrypt('private-merchant-key', 'key');
        $second = $cipher->encrypt('private-merchant-key', 'key');
        self::assertNotSame($first, $second);
        self::assertStringNotContainsString('private-merchant-key', $first);
        self::assertSame('private-merchant-key', $cipher->decrypt($first, 'key'));
    }

    public function testTamperingAndSwappingKeyWithSaltFailClosed(): void
    {
        $cipher = new PaymentSecretCipher(str_repeat('a', 64));
        $encrypted = $cipher->encrypt('private-merchant-key', 'key');
        $this->expectException(\InvalidArgumentException::class);
        $cipher->decrypt($encrypted, 'salt');
    }

    public function testWrongMasterSecretCannotDecrypt(): void
    {
        $encrypted = (new PaymentSecretCipher(str_repeat('a', 64)))->encrypt('private-merchant-key', 'key');
        $this->expectException(\InvalidArgumentException::class);
        (new PaymentSecretCipher(str_repeat('b', 64)))->decrypt($encrypted, 'key');
    }

    public function testModifiedCiphertextCannotDecrypt(): void
    {
        $cipher = new PaymentSecretCipher(str_repeat('a', 64));
        $encrypted = $cipher->encrypt('private-merchant-key', 'key');
        $raw = base64_decode(substr($encrypted, 3), true);
        $raw[25] = chr(ord($raw[25]) ^ 1);
        $this->expectException(\InvalidArgumentException::class);
        $cipher->decrypt('v1:'.base64_encode($raw), 'key');
    }
}
