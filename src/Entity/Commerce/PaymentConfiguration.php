<?php

declare(strict_types=1);

namespace App\Entity\Commerce;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** The singleton PayTR row. Only authenticated ciphertext is persisted for merchant secrets. */
#[ORM\Entity]
#[ORM\Table(name: 'payment_configuration')]
class PaymentConfiguration
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id = 1;

    #[ORM\Column(length: 100)]
    private string $merchantId = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $merchantKeyEncrypted = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $merchantSaltEncrypted = null;

    #[ORM\Column]
    private bool $testMode = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $merchantId = '', ?string $merchantKeyEncrypted = null, ?string $merchantSaltEncrypted = null, bool $testMode = true)
    {
        $this->merchantId = $merchantId;
        $this->merchantKeyEncrypted = $merchantKeyEncrypted;
        $this->merchantSaltEncrypted = $merchantSaltEncrypted;
        $this->testMode = $testMode;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): int { return $this->id; }
    public function merchantId(): string { return $this->merchantId; }
    public function merchantKeyEncrypted(): ?string { return $this->merchantKeyEncrypted; }
    public function merchantSaltEncrypted(): ?string { return $this->merchantSaltEncrypted; }
    public function testMode(): bool { return $this->testMode; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
