<?php

declare(strict_types=1);

namespace App\Module\Order;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\OrderStatusChange;
use App\Module\Admin\ConcurrentAdminEdit;
use App\Module\Audit\AuditAction;
use App\Module\Audit\AuditLogger;
use App\Module\Loyalty\RewardService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place an order's state changes under a staff decision.
 *
 * Every path that moves an order — the admin screens, and the payment callback that confirms
 * it — records an `OrderStatusChange`, and that row already names an actor. What it does not
 * carry is where the decision came from: `payment-gateway` and an operator's e-mail look
 * alike in the column, and "cancel" is indistinguishable from "confirm" without opening the
 * order. So a second, cross-cutting audit row is written here, naming both the actor and the
 * transition, on the same transaction as the change itself.
 *
 * Writing it inside `wrapInTransaction` and before the flush is deliberate: an audit row
 * describing a transition that then rolled back would be worse than no row at all.
 */
final readonly class AdminOrderManager
{
    public function __construct(
        private OrderRepositoryInterface $orders,
        private EntityManagerInterface $entityManager,
        private AuditLogger $audit,
        private RewardService $rewards,
    ) {
    }

    public function transition(string $orderNumber, OrderState $next, string $reason, int $expectedVersion, string $actorEmail): CustomerOrder
    {
        return $this->entityManager->wrapInTransaction(function () use ($orderNumber, $next, $reason, $expectedVersion, $actorEmail): CustomerOrder {
            $order = $this->orders->findOneByNumberForUpdate($orderNumber);
            if (null === $order) {
                throw new \DomainException('Order was not found.');
            }
            if ($order->version() !== $expectedVersion) {
                throw new ConcurrentAdminEdit('Order changed while this form was open. Reload and try again.');
            }
            $from = $order->state();
            $order->transitionTo($next);
            $this->entityManager->persist(new OrderStatusChange($order, $from, $next, $reason, $actorEmail));
            $this->orders->save($order);
            $this->audit->record(
                AuditAction::OrderStateChanged,
                $order->orderNumber(),
                [
                    'from_state' => $from->value,
                    'to_state' => $next->value,
                    'reason' => $reason,
                ],
            );
            $this->entityManager->flush();
            if (OrderState::Cancelled === $next) {
                $this->rewards->synchronize($order);
            }
            return $order;
        });
    }
}
