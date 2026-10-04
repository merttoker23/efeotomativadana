<?php

declare(strict_types=1);

namespace App\Module\Order;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Customer\CustomerUser;
use App\Module\Payment\PaymentAdminManager;
use App\Module\Payment\PaymentRefundService;
use App\Module\Payment\RefundRefused;
use App\Module\Shipping\ShipmentOrchestrator;
use App\Module\Shipping\ShipmentState;
use App\Repository\Commerce\PaymentRepository;
use App\Repository\Commerce\ShipmentRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CustomerOrderCancellationService
{
    private const string REASON = 'Müşteri tarafından iptal edildi';

    public function __construct(
        private OrderRepositoryInterface $orders,
        private PaymentRepository $payments,
        private ShipmentRepository $shipments,
        private PaymentAdminManager $paymentActions,
        private PaymentRefundService $refunds,
        private ShipmentOrchestrator $shipping,
        private OrderCancellationService $cancellation,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function canCancel(CustomerOrder $order): bool
    {
        $shipment = $this->shipments->findOneForOrder($order);

        return in_array($order->state(), [OrderState::Placed, OrderState::Confirmed], true)
            && !in_array($shipment?->state(), [ShipmentState::Ready, ShipmentState::InTransit, ShipmentState::Delivered], true);
    }

    public function cancel(CustomerUser $customer, string $orderNumber): CustomerOrder
    {
        $refused = null;
        $order = $this->entityManager->wrapInTransaction(function () use ($customer, $orderNumber, &$refused): CustomerOrder {
            $order = $this->orders->findOneByNumberForUpdate($orderNumber);
            if (null === $order || $order->customer()->id() !== $customer->id()) {
                throw new OrderNotFound('Order was not found.');
            }
            if (!in_array($order->state(), [OrderState::Placed, OrderState::Confirmed], true)) {
                throw new \DomainException('Bu sipariş artık iptal edilemez.');
            }
            // Same order -> payment locking used by initiation, callback and refund.
            $payment = $this->payments->findOneForUpdate($order);
            $shipment = $this->shipments->findOneForOrder($order);
            if (null !== $shipment) {
                $shipment = $this->shipments->findForUpdate((int) $shipment->id());
            }
            if (in_array($shipment?->state(), [ShipmentState::Ready, ShipmentState::InTransit, ShipmentState::Delivered], true)) {
                throw new \DomainException('Kargoya hazırlanmış sipariş için iade veya destek talebi oluşturun.');
            }
            $actor = $customer->getUserIdentifier();
            if (null !== $shipment && ShipmentState::Cancelled !== $shipment->state()) {
                $this->shipping->cancel($shipment, self::REASON, $actor);
            }
            if (null !== $payment && $payment->refundableAmount()->minorAmount() > 0 && $payment->state()->hasCapturedFunds()) {
                try {
                    // Reuse the provider's full-refund and idempotency logic. Announce after our commit.
                    $this->refunds->refund($order, $payment->refundableAmount(), self::REASON, $actor, false);
                } catch (RefundRefused $failure) {
                    // Preserve the existing refusal/audit record instead of rolling it back.
                    $refused = $failure;
                }
            } else {
                if (null !== $payment && $payment->state()->canBeRetried()) {
                    $this->paymentActions->cancel($order, self::REASON, $actor);
                }
                $this->cancellation->complete($order, self::REASON, $actor);
            }

            return $order;
        });
        if (null !== $refused) {
            throw $refused;
        }
        $this->cancellation->announce($order);

        return $order;
    }
}
