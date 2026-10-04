<?php

declare(strict_types=1);

namespace App\Module\Order;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\OrderStatusChange;
use App\Module\Audit\AuditAction;
use App\Module\Audit\AuditLogger;
use App\Module\Inventory\ProductInventoryRepositoryInterface;
use App\Module\Loyalty\RewardService;
use App\Module\Notification\Event\OrderCancelled;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/** Shared cancellation effects. Call complete inside the transaction holding the order lock. */
final readonly class OrderCancellationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProductInventoryRepositoryInterface $inventory,
        private AuditLogger $audit,
        private RewardService $rewards,
        private EventDispatcherInterface $events,
    ) {
    }

    public function complete(CustomerOrder $order, string $reason, string $actorEmail): bool
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Cancellation requires a transaction and a locked order.');
        }
        if (OrderState::Cancelled === $order->state()) {
            return false;
        }
        $from = $order->state();
        $order->transitionTo(OrderState::Cancelled);
        // The terminal transition is the durable once-only stock guard; replay exits above.
        // Match checkout's product/inventory lock order and sort to avoid multi-product deadlocks.
        $items = $order->items();
        usort($items, static fn ($a, $b): int => ($a->product()?->id() ?? 0) <=> ($b->product()?->id() ?? 0));
        foreach ($items as $item) {
            $product = $item->product();
            if (null === $product) {
                continue; // Deleted products retain their immutable line snapshot.
            }
            $this->entityManager->refresh($product, LockMode::PESSIMISTIC_WRITE);
            $inventory = $this->inventory->findOneByProductForUpdate($product)
                ?? throw new \DomainException('The reserved product inventory is missing.');
            $inventory->adjust($item->quantity());
        }
        $this->entityManager->persist(new OrderStatusChange($order, $from, OrderState::Cancelled, $reason, $actorEmail));
        $this->audit->record(AuditAction::OrderStateChanged, $order->orderNumber(), [
            'from_state' => $from->value,
            'to_state' => OrderState::Cancelled->value,
            'reason' => $reason,
            'actor' => $actorEmail,
            'customer_id' => $order->customer()->id(),
        ]);
        $this->entityManager->flush();
        $this->rewards->synchronize($order);

        return true;
    }

    /** Announce only after the caller's outer transaction commits. Dedup is order-scoped. */
    public function announce(CustomerOrder $order): void
    {
        if (OrderState::Cancelled === $order->state()) {
            $this->events->dispatch(new OrderCancelled($order));
        }
    }
}
