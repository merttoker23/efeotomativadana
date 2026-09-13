<?php

namespace App\Module\Pricing;

final readonly class TaxRate
{
    private function __construct(private int $basisPoints)
    {
    }

    public static function fromPercentage(mixed $percentage): self
    {
        if (!is_int($percentage)) {
            throw new \InvalidArgumentException('Tax percentage must be an integer.');
        }

        if ($percentage < 0 || $percentage > 100) {
            throw new \InvalidArgumentException('Tax percentage must be between 0 and 100.');
        }

        return new self($percentage * 100);
    }

    public static function fromBasisPoints(mixed $basisPoints): self
    {
        if (!is_int($basisPoints)) {
            throw new \InvalidArgumentException('Tax basis points must be an integer.');
        }

        if ($basisPoints < 0 || $basisPoints > 10000) {
            throw new \InvalidArgumentException('Tax basis points must be between 0 and 10000.');
        }

        return new self($basisPoints);
    }

    public function basisPoints(): int
    {
        return $this->basisPoints;
    }
}
