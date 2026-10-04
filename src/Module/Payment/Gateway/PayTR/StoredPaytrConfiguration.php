<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway\PayTR;

use App\Module\Settings\PaymentSettingsData;
use App\Module\Settings\StoreConfiguration;
use Doctrine\DBAL\Connection;

/** Reads DBAL directly so even a long-lived worker sees the latest settings on every operation. */
final readonly class StoredPaytrConfiguration implements PaytrConfigurationSource
{
    public function __construct(
        private Connection $connection,
        private PaymentSecretCipher $cipher,
        private StoreConfiguration $store,
    ) {
    }

    public function current(): PaytrConfiguration
    {
        $row = $this->connection->fetchAssociative('SELECT merchant_id, merchant_key_encrypted, merchant_salt_encrypted, test_mode FROM payment_configuration WHERE id = 1');
        $key = $salt = '';
        try {
            if (is_array($row) && is_string($row['merchant_key_encrypted']) && is_string($row['merchant_salt_encrypted'])) {
                $key = $this->cipher->decrypt($row['merchant_key_encrypted'], 'key');
                $salt = $this->cipher->decrypt($row['merchant_salt_encrypted'], 'salt');
            }
        } catch (\InvalidArgumentException) {
            // Corrupt ciphertext or a changed master secret makes PayTR unavailable.
            $key = $salt = '';
        }

        return new PaytrConfiguration(
            is_array($row) ? (string) $row['merchant_id'] : '',
            $key,
            $salt,
            !is_array($row) || (bool) $row['test_mode'],
            'https://www.paytr.com/odeme',
            'https://www.paytr.com/odeme/iade',
        );
    }

    /** Never decrypt secrets into the admin form or its HTML. */
    public function formData(): PaymentSettingsData
    {
        $row = $this->connection->fetchAssociative('SELECT merchant_id, test_mode FROM payment_configuration WHERE id = 1');
        $data = new PaymentSettingsData();
        $data->paymentProvider = $this->store->paymentProvider();
        if (is_array($row)) {
            $data->merchantId = (string) $row['merchant_id'];
            $data->testMode = (bool) $row['test_mode'];
        }

        return $data;
    }

    /** @return array{key: bool, salt: bool} */
    public function secretStatus(): array
    {
        $row = $this->connection->fetchAssociative('SELECT merchant_key_encrypted IS NOT NULL AS key_present, merchant_salt_encrypted IS NOT NULL AS salt_present FROM payment_configuration WHERE id = 1');

        return ['key' => is_array($row) && (bool) $row['key_present'], 'salt' => is_array($row) && (bool) $row['salt_present']];
    }

    public function save(#[\SensitiveParameter] PaymentSettingsData $data): void
    {
        if (!$data->testMode && !$data->confirmLiveMode) {
            throw new \InvalidArgumentException('Live mode requires acknowledgement.');
        }
        $values = ['merchant_id' => trim($data->merchantId), 'test_mode' => (int) $data->testMode, 'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')];
        foreach (['merchantKey' => 'key', 'merchantSalt' => 'salt'] as $property => $purpose) {
            if ('' !== trim($data->$property)) {
                $values['merchant_'.$purpose.'_encrypted'] = $this->cipher->encrypt(trim($data->$property), $purpose);
            }
        }
        // Remove submitted secrets as soon as encryption is complete.
        $data->merchantKey = $data->merchantSalt = '';
        $this->connection->transactional(function () use ($data, $values): void {
            $this->connection->update('payment_configuration', $values, ['id' => 1]);
            $this->store->savePaymentProvider($data->paymentProvider);
        });
    }
}
