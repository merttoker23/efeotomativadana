<?php

namespace App\Shared\Money;

use Symfony\Component\Intl\Currencies;

final readonly class Money
{
    private function __construct(
        private int $minorAmount,
        private string $currency,
    ) {
    }

    public static function ofMinor(mixed $minorAmount, string $currency): self
    {
        if (!is_int($minorAmount)) {
            throw new \InvalidArgumentException('Money minor amount must be an integer.');
        }

        if ($minorAmount < 0) {
            throw new \InvalidArgumentException('Money minor amount cannot be negative.');
        }

        $currency = strtoupper(trim($currency));
        if (3 !== strlen($currency) || !Currencies::exists($currency)) {
            throw new \InvalidArgumentException('Money currency must be a valid ISO-4217 currency code.');
        }

        return new self($minorAmount, $currency);
    }

    public function minorAmount(): int
    {
        return $this->minorAmount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function isZero(): bool
    {
        return 0 === $this->minorAmount;
    }

    public function equals(self $other): bool
    {
        return $this->minorAmount === $other->minorAmount && $this->currency === $other->currency;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);
        if ($other->minorAmount > PHP_INT_MAX - $this->minorAmount) {
            throw new \OverflowException('Money addition exceeds the integer range.');
        }

        return new self($this->minorAmount + $other->minorAmount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);
        if ($other->minorAmount > $this->minorAmount) {
            throw new \InvalidArgumentException('Money subtraction cannot produce a negative amount.');
        }

        return new self($this->minorAmount - $other->minorAmount, $this->currency);
    }

    public function multiply(mixed $quantity): self
    {
        if (!is_int($quantity)) {
            throw new \InvalidArgumentException('Money quantity must be an integer.');
        }

        if ($quantity < 0) {
            throw new \InvalidArgumentException('Money quantity cannot be negative.');
        }

        if (0 === $quantity) {
            return new self(0, $this->currency);
        }

        if ($this->minorAmount > intdiv(PHP_INT_MAX, $quantity)) {
            throw new \OverflowException('Money multiplication exceeds the integer range.');
        }

        return new self($this->minorAmount * $quantity, $this->currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException('Money arithmetic requires matching currencies.');
        }
    }
}
