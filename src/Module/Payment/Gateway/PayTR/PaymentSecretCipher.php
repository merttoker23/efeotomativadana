<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway\PayTR;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** AES-256-GCM with a random nonce and a purpose-specific key derived from the server secret. */
final readonly class PaymentSecretCipher
{
    public function __construct(
        #[Autowire('%env(APP_SECRET)%')]
        #[\SensitiveParameter]
        private string $masterSecret,
    ) {
    }

    public function encrypt(#[\SensitiveParameter] string $secret, string $purpose): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', $this->key($purpose), OPENSSL_RAW_DATA, $nonce, $tag, $purpose, 16);
        if (false === $ciphertext) {
            throw new \InvalidArgumentException('Payment credential encryption failed.');
        }

        return 'v1:'.base64_encode($nonce.$tag.$ciphertext);
    }

    public function decrypt(#[\SensitiveParameter] string $encrypted, string $purpose): string
    {
        $raw = str_starts_with($encrypted, 'v1:') ? base64_decode(substr($encrypted, 3), true) : false;
        if (false === $raw || strlen($raw) <= 28) {
            throw new \InvalidArgumentException('Payment credential is unreadable.');
        }
        $secret = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key($purpose), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $purpose);
        if (false === $secret) {
            throw new \InvalidArgumentException('Payment credential authentication failed.');
        }

        return $secret;
    }

    private function key(string $purpose): string
    {
        if (strlen($this->masterSecret) < 32 || !in_array($purpose, ['key', 'salt'], true)) {
            throw new \InvalidArgumentException('A strong server secret is required for payment credentials.');
        }

        return hash_hkdf('sha256', $this->masterSecret, 32, 'paytr-credential-v1:'.$purpose);
    }

    public function __debugInfo(): array
    {
        return [];
    }
}
