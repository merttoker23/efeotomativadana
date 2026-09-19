<?php

namespace App\Entity\Customer;

use App\Repository\Customer\PasswordResetTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PasswordResetTokenRepository::class)]
#[ORM\Table(name: 'customer_password_reset_token')]
#[ORM\UniqueConstraint(name: 'uniq_customer_reset_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_customer_reset_token_owner', columns: ['customer_id'])]
class PasswordResetToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CustomerUser::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CustomerUser $customer;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(CustomerUser $customer, string $tokenHash, \DateTimeImmutable $expiresAt, \DateTimeImmutable $createdAt)
    {
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $tokenHash)) {
            throw new \InvalidArgumentException('Reset token hash must be a SHA-256 hexadecimal digest.');
        }

        $this->customer = $customer;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt;
    }

    public function customer(): CustomerUser { return $this->customer; }
    public function id(): ?int { return $this->id; }
    public function tokenHash(): string { return $this->tokenHash; }
    public function expiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function usedAt(): ?\DateTimeImmutable { return $this->usedAt; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isUsableAt(\DateTimeImmutable $now): bool
    {
        return null === $this->usedAt && $now < $this->expiresAt;
    }

    public function consume(\DateTimeImmutable $now): void
    {
        if (!$this->isUsableAt($now)) {
            throw new \DomainException('The password reset token is invalid, expired, or already used.');
        }

        $this->usedAt = $now;
    }
}
