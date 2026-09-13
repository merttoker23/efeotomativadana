<?php

namespace App\Module\Pricing;

final readonly class TaxCategory
{
    private function __construct(private string $key)
    {
    }

    public static function of(string $key): self
    {
        $key = mb_strtolower(trim($key));
        if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key)) {
            throw new \InvalidArgumentException('Tax category key must contain lowercase letters, numbers, and hyphens.');
        }

        return new self($key);
    }

    public static function standard(): self
    {
        return new self('standard');
    }

    public function key(): string
    {
        return $this->key;
    }
}
