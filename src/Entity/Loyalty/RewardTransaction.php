<?php

declare(strict_types=1);

namespace App\Entity\Loyalty;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Customer\CustomerUser;
use App\Module\Loyalty\RewardKind;
use App\Repository\Loyalty\RewardTransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Append-only facts; corrections create a new entry, never edit an old one. */
#[ORM\Entity(repositoryClass: RewardTransactionRepository::class)]
#[ORM\Table(name: 'loyalty_reward_transaction')]
#[ORM\UniqueConstraint(name: 'uniq_reward_source', columns: ['source_key'])]
#[ORM\Index(name: 'idx_reward_customer_created', columns: ['customer_id', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_reward_order_kind', columns: ['order_id', 'kind'])]
#[ORM\HasLifecycleCallbacks]
final class RewardTransaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: CustomerUser::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private CustomerUser $customer,
        #[ORM\ManyToOne(targetEntity: CustomerOrder::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
        private ?CustomerOrder $order,
        #[ORM\Column(length: 30, enumType: RewardKind::class)]
        private RewardKind $kind,
        #[ORM\Column(type: Types::BIGINT)]
        private int $points,
        #[ORM\Column(length: 120)]
        private string $sourceKey,
        #[ORM\Column(length: 255)]
        private string $reason,
        #[ORM\Column(length: 180, nullable: true)]
        private ?string $actorEmail,
        #[ORM\Column(type: Types::BIGINT, nullable: true)]
        private ?int $eligibleMinor,
        #[ORM\Column(nullable: true)]
        private ?int $earnPercentage,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        if ('' === trim($sourceKey) || strlen($sourceKey) > 120 || '' === trim($reason) || mb_strlen($reason) > 255) {
            throw new \InvalidArgumentException('Reward entries require a bounded source key and reason.');
        }
        if (RewardKind::ManualAdjustment === $kind && (null === $actorEmail || false === filter_var($actorEmail, FILTER_VALIDATE_EMAIL) || 0 === $points)) {
            throw new \InvalidArgumentException('Manual reward adjustment requires an administrator and nonzero points.');
        }
        if (RewardKind::Earn === $kind && ($points < 0 || null === $eligibleMinor || $eligibleMinor < 0 || null === $earnPercentage || $earnPercentage < 0 || $earnPercentage > 100)) {
            throw new \InvalidArgumentException('Earn requires a valid eligible amount and percentage snapshot.');
        }
        if (RewardKind::Reversal === $kind && $points >= 0) {
            throw new \InvalidArgumentException('A reversal must remove points.');
        }
        if (null !== $order && $order->customer() !== $customer && $order->customer()->id() !== $customer->id()) {
            throw new \InvalidArgumentException('Reward order must belong to the customer.');
        }
    }

    public function id(): ?int { return $this->id; }
    public function customer(): CustomerUser { return $this->customer; }
    public function order(): ?CustomerOrder { return $this->order; }
    public function kind(): RewardKind { return $this->kind; }
    public function points(): int { return $this->points; }
    public function sourceKey(): string { return $this->sourceKey; }
    public function reason(): string { return $this->reason; }
    public function actorEmail(): ?string { return $this->actorEmail; }
    public function eligibleMinor(): ?int { return $this->eligibleMinor; }
    public function earnPercentage(): ?int { return $this->earnPercentage; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }

    #[ORM\PreUpdate]
    #[ORM\PreRemove]
    public function rejectMutation(): never
    {
        throw new \DomainException('Reward ledger entries cannot be changed or deleted.');
    }
}
