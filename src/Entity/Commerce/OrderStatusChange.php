<?php

declare(strict_types=1);

namespace App\Entity\Commerce;

use App\Module\Order\OrderState;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'commerce_order_status_change')]
#[ORM\Index(name: 'idx_order_status_change_order_time', columns: ['order_id', 'changed_at'])]
final class OrderStatusChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CustomerOrder::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CustomerOrder $order;

    #[ORM\Column(length: 20, enumType: OrderState::class)]
    private OrderState $fromState;

    #[ORM\Column(length: 20, enumType: OrderState::class)]
    private OrderState $toState;

    #[ORM\Column(length: 500)]
    private string $reason;

    #[ORM\Column(length: 180)]
    private string $actorEmail;

    #[ORM\Column]
    private \DateTimeImmutable $changedAt;

    public function __construct(CustomerOrder $order, OrderState $fromState, OrderState $toState, string $reason, string $actorEmail)
    {
        $reason = trim($reason);
        $actorEmail = mb_strtolower(trim($actorEmail));
        if ('' === $reason || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('Order status reason must contain between 1 and 500 characters.');
        }
        if ('' === $actorEmail || mb_strlen($actorEmail) > 180) {
            throw new \InvalidArgumentException('Order status actor email is invalid.');
        }

        $this->order = $order;
        $this->fromState = $fromState;
        $this->toState = $toState;
        $this->reason = $reason;
        $this->actorEmail = $actorEmail;
        $this->changedAt = new \DateTimeImmutable();
    }

    public function id(): ?int { return $this->id; }
    public function order(): CustomerOrder { return $this->order; }
    public function fromState(): OrderState { return $this->fromState; }
    public function toState(): OrderState { return $this->toState; }
    public function reason(): string { return $this->reason; }
    public function actorEmail(): string { return $this->actorEmail; }
    public function changedAt(): \DateTimeImmutable { return $this->changedAt; }
}
