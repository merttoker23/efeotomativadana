<?php

declare(strict_types=1);

namespace App\Module\Notification;

/**
 * The messages this store sends to a customer, and nothing else.
 *
 * Every case is a *fact that already happened* — an order placed, money taken, a parcel sent, a
 * return asked about. None of them asks the customer to do anything and none of them carries a
 * payment link, so a message can never become a way to move money by clicking it.
 *
 * Marketing is deliberately not modelled here and cannot be added by configuration: a promotion is
 * a different product with different rules about consent and frequency, and folding it into the
 * transactional enum is how a store ends up mailing people who never asked.
 */
enum NotificationType: string
{
    case OrderPlaced = 'order_placed';
    case PaymentReceived = 'payment_received';
    case PaymentFailed = 'payment_failed';
    case ShipmentDispatched = 'shipment_dispatched';
    case ShipmentDelivered = 'shipment_delivered';
    case ReturnRequested = 'return_requested';
    case ReturnApproved = 'return_approved';
    case ReturnRejected = 'return_rejected';
    case ReturnCompleted = 'return_completed';

    /**
     * The file name stem of the templates that render this notification.
     *
     * Both parts come from the enum case rather than from a caller-supplied string, so a template
     * name is never something a request can choose.
     */
    public function templateStem(): string
    {
        return $this->value;
    }

    /** The subject line, in the store's own voice. */
    public function subject(): string
    {
        return match ($this) {
            NotificationType::OrderPlaced => 'Siparişiniz alındı',
            NotificationType::PaymentReceived => 'Ödemeniz alındı',
            NotificationType::PaymentFailed => 'Ödemeniz tamamlanamadı',
            NotificationType::ShipmentDispatched => 'Siparişiniz yola çıktı',
            NotificationType::ShipmentDelivered => 'Siparişiniz teslim edildi',
            NotificationType::ReturnRequested => 'İade talebiniz alındı',
            NotificationType::ReturnApproved => 'İade talebiniz onaylandı',
            NotificationType::ReturnRejected => 'İade talebiniz reddedildi',
            NotificationType::ReturnCompleted => 'İade işleminiz tamamlandı',
        };
    }
}
