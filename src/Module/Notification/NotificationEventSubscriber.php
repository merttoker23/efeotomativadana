<?php

declare(strict_types=1);

namespace App\Module\Notification;

use App\Module\Notification\Event\OrderPlaced;
use App\Module\Notification\Event\PaymentCaptured;
use App\Module\Notification\Event\ReturnStateChanged;
use App\Module\Notification\Event\ShipmentMoved;
use App\Module\Returns\ReturnState;
use App\Module\Shipping\ShipmentState;
use App\Shared\Logging\SecretRedactor;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Turns what happened into what the customer is told, in one place.
 *
 * The single reason this class exists rather than four `publish()` calls scattered through the
 * services: a commerce service that constructs an email knows it is sending email, and the next
 * thing anybody adds is a `try { $mailer->send() } catch {}` around it. Here, the services raise
 * facts and know nothing about mail.
 *
 * A duplicate is swallowed and logged rather than raised, because a *replayed* event is normal —
 * PayTR delivers a notification twice often enough to matter — and the one thing the phase must
 * guarantee is that a replay never produces a second email. A genuine failure is re-raised for the
 * same reason {@see TransactionalNotificationService} re-raises one.
 */
final readonly class NotificationEventSubscriber
{
    public function __construct(
        private TransactionalNotificationService $notifications,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onOrderPlaced(OrderPlaced $event): void
    {
        $this->publish(new NotificationEvent(NotificationType::OrderPlaced, $event->order->orderNumber(), $event->order->customerEmail(), [
            'order_number' => $event->order->orderNumber(),
            'customer_name' => $event->order->customerName(),
        ]));
    }

    #[AsEventListener]
    public function onPaymentCaptured(PaymentCaptured $event): void
    {
        $this->publish(new NotificationEvent(NotificationType::PaymentReceived, $event->order->orderNumber(), $event->order->customerEmail(), [
            'order_number' => $event->order->orderNumber(),
            'customer_name' => $event->order->customerName(),
        ]));
    }

    /**
     * Only the two transitions a customer is waiting to hear about.
     *
     * A parcel being prepared, in transit or refused is not news a customer needs by mail, and
     * "ready" then "in transit" then "delivered" would be three messages about one parcel.
     */
    #[AsEventListener]
    public function onShipmentMoved(ShipmentMoved $event): void
    {
        $type = match ($event->shipment->state()) {
            ShipmentState::Ready, ShipmentState::InTransit => NotificationType::ShipmentDispatched,
            ShipmentState::Delivered => NotificationType::ShipmentDelivered,
            default => null,
        };
        if (null === $type) {
            return;
        }

        $this->publish(new NotificationEvent($type, $event->orderNumber(), $event->recipient(), [
            'order_number' => $event->orderNumber(),
            'customer_name' => $event->shipment->order()->customerName(),
            'tracking_number' => $event->trackingNumber(),
        ]));
    }

    #[AsEventListener]
    public function onReturnStateChanged(ReturnStateChanged $event): void
    {
        $type = match ($event->state()) {
            ReturnState::Requested => NotificationType::ReturnRequested,
            ReturnState::Approved => NotificationType::ReturnApproved,
            ReturnState::Rejected => NotificationType::ReturnRejected,
            ReturnState::Refunded => NotificationType::ReturnCompleted,
            // Received is an internal step between "you sent it back" and "we have paid you"; the
            // customer is told the outcome, not the paperwork.
            ReturnState::Withdrawn, ReturnState::Received => null,
        };
        if (null === $type) {
            return;
        }

        $payload = [
            'return_number' => $event->returnNumber(),
            'order_number' => $event->orderNumber(),
            'customer_name' => $event->return->customerName(),
            'staff_note' => $event->detail(),
        ];
        if (ReturnState::Refunded === $event->state()) {
            $payload['refund_amount'] = $event->detail();
        }

        $this->publish(new NotificationEvent($type, $event->returnNumber(), $event->recipient(), $payload));
    }

    private function publish(NotificationEvent $event): void
    {
        try {
            $this->notifications->publish($event);
        } catch (NotificationAlreadySent $alreadySent) {
            // Expected: the provider reported the same fact twice. Logged, not raised, because the
            // guarantee we owe is "no second email", and that guarantee is already met.
            $this->logger->info('Transactional notification skipped because it was already sent.', [
                'dedup_key' => $event->dedupKey(),
            ]);
        } catch (\Throwable $failure) {
            $this->logger->error('Transactional notification could not be sent.', [
                'dedup_key' => $event->dedupKey(),
                'type' => $event->type->value,
                // The class and a redacted message, never the exception object. Monolog would
                // normalise a Throwable into its message plus a full stack trace, and that
                // message is the one part of this record an attacker partly chooses — a mailer
                // refusal quoting the address it failed to reach, for instance.
                'exception_class' => $failure::class,
                'message' => SecretRedactor::text($failure->getMessage()),
            ]);

            throw $failure;
        }
    }
}
