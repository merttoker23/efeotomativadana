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
    private ?string $customerName;

    private ?string $customerPhone;

    private ?string $customerAddress;

    /** @var list<array{string, string, int}> */
    private array $basketLines;

    /**
     * @param list<array{string, string, int}> $basketLines label, dot-decimal unit price, quantity
     */
    public function __construct(
        private string $orderNumber,
        private string $attemptSequence,
        private string $returnToken,
        private Money $amount,
        private string $returnUrl,
        private string $cancelUrl,
        private string $customerEmail,
        private string $locale,
        ?string $customerName = null,
        ?string $customerPhone = null,
        ?string $customerAddress = null,
        array $basketLines = [],
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

        $this->customerName = self::optionalText($customerName);
        $this->customerPhone = self::optionalText($customerPhone);
        $this->customerAddress = self::optionalText($customerAddress);
        $this->basketLines = self::validatedBasketLines($basketLines);
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

    /** Full name, for a provider that requires one. Absent when the order did not capture it. */
    public function customerName(): ?string { return $this->customerName; }

    /** Contact phone, for a provider that requires one. */
    public function customerPhone(): ?string { return $this->customerPhone; }

    /** Delivery address as a single line, for a provider that requires one. */
    public function customerAddress(): ?string { return $this->customerAddress; }

    /**
     * The order's own lines, so a provider receives a real basket.
     *
     * @return list<array{string, string, int}>
     */
    public function basketLines(): array { return $this->basketLines; }

    /** A blank optional value is absent, never an empty string a provider would have to guess at. */
    private static function optionalText(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * @param array<mixed> $lines
     *
     * @return list<array{string, string, int}>
     */
    private static function validatedBasketLines(array $lines): array
    {
        $validated = [];
        foreach ($lines as $line) {
            if (!\is_array($line) || 3 !== \count($line)) {
                throw new \InvalidArgumentException('A payment basket line must be a name, a unit price and a quantity.');
            }
            $values = array_values($line);
            $name = trim((string) $values[0]);
            $price = trim((string) $values[1]);
            $quantity = $values[2];

            if ('' === $name) {
                throw new \InvalidArgumentException('A payment basket line must be named.');
            }
            // A dot-decimal price with at most two places: the shape every documented provider
            // expects, and the only one that cannot be misread as a thousands separator.
            if (1 !== preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $price)) {
                throw new \InvalidArgumentException('A payment basket line needs a dot-decimal unit price.');
            }
            if (!\is_int($quantity) || $quantity < 1) {
                throw new \InvalidArgumentException('A payment basket line needs a positive whole quantity.');
            }

            $validated[] = [$name, $price, $quantity];
        }

        return $validated;
    }

    private function assertHttpsUrl(string $url, string $role): void
    {
        $url = trim($url);
        if (false === filter_var($url, FILTER_VALIDATE_URL) || 'https' !== parse_url($url, PHP_URL_SCHEME)) {
            throw new \InvalidArgumentException(sprintf('Payment initiation %s URL must be an absolute https URL.', $role));
        }
    }
}
