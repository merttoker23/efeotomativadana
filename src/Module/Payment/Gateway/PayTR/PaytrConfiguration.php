<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway\PayTR;

/**
 * Everything the PayTR adapter needs from configuration, and nothing else.
 *
 * The adapter is wired in every environment, including one where the merchant has not entered
 * their panel details yet, so this object is constructible while empty and reports
 * {@see isConfigured()} instead of refusing to boot. That is what lets the gateway refuse to
 * offer a payment it could not take, and lets an administrator finish the setup later without a
 * code change.
 *
 * Credentials are never defaulted here: they come from the environment, and the adapter refuses
 * to sign anything until all three are present.
 */
final readonly class PaytrConfiguration
{
    public function __construct(
        private string $merchantId,
        private string $merchantKey,
        private string $merchantSalt,
        private bool $testMode,
        private string $paymentUrl,
        private string $refundUrl,
    ) {
    }

    public static function fromEnvironment(
        string $merchantId,
        string $merchantKey,
        string $merchantSalt,
        bool|string $testMode,
        string $paymentUrl,
        string $refundUrl,
    ): self {
        return new self(
            trim($merchantId),
            trim($merchantKey),
            trim($merchantSalt),
            self::toBool($testMode),
            trim($paymentUrl),
            trim($refundUrl),
        );
    }

    /** False while any of the three panel credentials is missing, whatever the environment. */
    public function isConfigured(): bool
    {
        return '' !== $this->merchantId && '' !== $this->merchantKey && '' !== $this->merchantSalt;
    }

    /**
     * @throws \InvalidArgumentException when the merchant has not entered their details yet, so
     *                                   an unconfigured store fails loudly instead of sending an
     *                                   unsigned request PayTR would only reject
     */
    public function signature(): PaytrSignature
    {
        if (!$this->isConfigured()) {
            throw new \InvalidArgumentException('PayTR merchant credentials are not configured.');
        }

        return new PaytrSignature($this->merchantKey, $this->merchantSalt);
    }

    public function merchantId(): string
    {
        if (!$this->isConfigured()) {
            throw new \InvalidArgumentException('PayTR merchant credentials are not configured.');
        }

        return $this->merchantId;
    }

    public function testMode(): bool
    {
        return $this->testMode;
    }

    /** The provider's own spelling of the test switch. */
    public function testModeFlag(): string
    {
        return $this->testMode ? '1' : '0';
    }

    /**
     * The address the customer's browser posts the card form to.
     *
     * Validated on use rather than on construction: a bad endpoint must make the payment option
     * unavailable, not take the whole site down while the container is being built.
     */
    public function paymentUrl(): string
    {
        if (!$this->isConfigured()) {
            throw new \InvalidArgumentException('PayTR merchant credentials are not configured.');
        }

        return $this->assertHttps($this->paymentUrl, 'payment');
    }

    public function refundUrl(): string
    {
        if (!$this->isConfigured()) {
            throw new \InvalidArgumentException('PayTR merchant credentials are not configured.');
        }

        return $this->assertHttps($this->refundUrl, 'refund');
    }

    private static function toBool(bool|string $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function assertHttps(string $url, string $role): string
    {
        if (false === filter_var($url, \FILTER_VALIDATE_URL) || 'https' !== parse_url($url, \PHP_URL_SCHEME)) {
            throw new \InvalidArgumentException(sprintf('PayTR %s endpoint must be an absolute https URL.', $role));
        }

        return $url;
    }
}
