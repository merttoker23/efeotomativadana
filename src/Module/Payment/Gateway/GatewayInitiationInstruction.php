<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway;

use App\Module\Payment\SanitizedFailure;
use App\Shared\Money\Money;
use Doctrine\DBAL\Types\Types;

/**
 * Everything a gateway needs to start one payment attempt, and nothing more.
 *
 * There is no card field, no PAN slot and no CVC slot anywhere in this object, by
 * construction: a gateway that needs card data must tokenize it in the customer's browser
 * or on a hosted page, so the store never sees it.
 */
final readonly class GatewayInitiationInstruction
{
    public function __construct(
        private string $orderNumber,
        private string $attemptSequence,
        private string $returnToken,
        private Money $amount,
        private string $returnUrl,
        private string $cancelUrl,
        private string $customerEmail,
        private string $locale,
    ) {
        if (1 !== preg_match('/^EOA-\d{8}-[0-9A-F]{12}$/', trim($this->orderNumber))) {
            throw new \InvalidArgumentException('Payment initiation order number has an invalid format.');
        }
        if ($this->amount->isZero()) {
            throw new \InvalidArgumentException('Payment initiation amount must be greater than zero.');
        }
        $this->assertHttpsUrl($this->returnUrl, 'return');
        $this->assertHttpsUrl($this->cancelUrl, 'cancel');
        if ('' === trim($this->attemptSequence) || '' === trim($this->returnToken)) {
            throw new \InvalidArgumentException('Payment initiation attempt identity is required.');
        }
        if (false === filter_var(trim($this->customerEmail), FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Payment initiation customer email is invalid.');
        }
    }

    public function orderNumber(): string { return $this->orderNumber; }

    /** Human-readable attempt identity the provider can echo back in its callback. */
    public function attemptSequence(): string { return $this->attemptSequence; }

    /** The unguessable token that addresses this attempt in the callback route. */
    public function returnToken(): string { return $this->returnToken; }

    public function amount(): Money { return $this->amount; }

    public function returnUrl(): string { return $this->returnUrl; }

    public function cancelUrl(): string { return $this->cancelUrl; }

    public function customerEmail(): string { return mb_strtolower(trim($this->customerEmail)); }

    public function locale(): string { return trim($this->locale); }

    private function assertHttpsUrl(string $url, string $role): void
    {
        $url = trim($url);
        if (false === filter_var($url, FILTER_VALIDATE_URL) || 'https' !== parse_url($url, PHP_URL_SCHEME)) {
            throw new \InvalidArgumentException(sprintf('Payment initiation %s URL must be an absolute https URL.', $role));
        }
    }
}
