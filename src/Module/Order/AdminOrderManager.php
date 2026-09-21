<?php

declare(strict_types=1);

namespace App\Module\Order;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\OrderStatusChange;
use App\Module\Admin\ConcurrentAdminEdit;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminOrderManager
{
    public function __construct(private OrderRepositoryInterface $orders, private EntityManagerInterface $entityManager)
    {
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
            $this->entityManager->flush();
            return $order;
        });
    }
}
