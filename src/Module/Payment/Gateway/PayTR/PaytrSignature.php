<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway\PayTR;

/**
 * PayTR's three HMAC-SHA256/base64 token shapes, in one place.
 *
 * The provider places the merchant salt differently in each of them: appended to the hash
 * string for the token request, in the middle for the callback hash, and appended after the
 * amount for the refund. That asymmetry is invisible in the documentation's prose and easy to
 * get wrong, so each shape is a named method rather than one configurable helper.
 */
final readonly class PaytrSignature
{
    public function __construct(
        private string $merchantKey,
        private string $merchantSalt,
    ) {
        if ('' === trim($this->merchantKey)) {
            throw new \InvalidArgumentException('PayTR merchant key must not be empty.');
        }
        if ('' === trim($this->merchantSalt)) {
            throw new \InvalidArgumentException('PayTR merchant salt must not be empty.');
        }
    }

    /**
     * `paytr_token` for the token request. The caller supplies the already concatenated hash
     * string because its field order is part of the documented contract.
     */
    public function initiationToken(string $hashString): string
    {
        return $this->sign($hashString.$this->merchantSalt);
    }

    /** `paytr_token` for a refund request. */
    public function refundToken(string $merchantId, string $merchantOid, string $returnAmount): string
    {
        return $this->sign($merchantId.$merchantOid.$returnAmount.$this->merchantSalt);
    }

    /** The `hash` PayTR sends with every payment notification. */
    public function callbackHash(string $merchantOid, string $status, string $totalAmount): string
    {
        return $this->sign($merchantOid.$this->merchantSalt.$status.$totalAmount);
    }

    /**
     * Compared in constant time, and a blank hash is refused before any comparison: an empty
     * string is never a valid PayTR hash, and letting it reach hash_equals would still burn a
     * comparison on a value the provider could not have produced.
     */
    public function callbackHashMatches(string $merchantOid, string $status, string $totalAmount, string $provided): bool
    {
        $provided = trim($provided);
        if ('' === $provided) {
            return false;
        }

        return hash_equals($this->callbackHash($merchantOid, $status, $totalAmount), $provided);
    }

    private function sign(string $payload): string
    {
        return base64_encode(hash_hmac('sha256', $payload, $this->merchantKey, true));
    }
}
