<?php

declare(strict_types=1);

namespace App\Module\Audit;

/**
 * The catalog of actions this store is willing to attribute to somebody.
 *
 * This is an enumeration rather than a free-text string because an audit trail that accepts
 * any string at all cannot be queried afterwards: "show me every privileged action in the
 * last week" is only answerable if the set of answers is known in advance. Adding a case here
 * is the visible, reviewable event that a new privileged action exists.
 *
 * Values are stable and stored as-is. They are identifiers, not sentences, so they are never
 * reworded for readability later — a reworded value is a different action as far as an audit
 * query is concerned.
 */
enum AuditAction: string
{
    // Authentication. Recorded on the firewall's own events so a brute-force run is visible
    // whether it targeted the storefront or the admin.
    case LoginSucceeded = 'security.login_succeeded';
    case LoginFailed = 'security.login_failed';
    case LoggedOut = 'security.logged_out';

    // Store configuration. `commerce.b2b_enabled` and the provider keys decide whether an
    // external integration runs at all, so a change to them is the first thing an operator
    // needs to be able to answer for.
    case SettingsUpdated = 'settings.updated';

    // Commerce.
    case OrderStateChanged = 'order.state_changed';
    case CustomerStatusChanged = 'customer.status_changed';

    // Payments and shipping: every operation that can move money or a parcel is attributed
    // even when the aggregate beside it already records an event, because that event answers
    // "what happened to this order" while this row answers "who did it, from where".
    case PaymentRetried = 'payment.retried_by_staff';
    case PaymentCancelled = 'payment.cancelled_by_staff';
    case PaymentRefunded = 'payment.refunded';
    case ShipmentCreated = 'shipment.created';
    case ShipmentHandedOver = 'shipment.handed_over';
    case ShipmentMarkedInTransit = 'shipment.marked_in_transit';
    case ShipmentMarkedDelivered = 'shipment.marked_delivered';
    case ShipmentCancelled = 'shipment.cancelled';
    case ShipmentLabelRequested = 'shipment.label_requested';
    case ShipmentStatusRefreshed = 'shipment.status_refreshed';
    case ShipmentCreationRetried = 'shipment.creation_retried';

    // Returns.
    case ReturnRequested = 'return.requested';
    case ReturnApproved = 'return.approved';
    case ReturnRejected = 'return.rejected';
    case ReturnReceived = 'return.received';
    case ReturnRefundRecorded = 'return.refund_recorded';
    case ReturnWithdrawn = 'return.withdrawn_by_customer';

    // Integrations. A manual run is a staff decision with an operational cost, so it is
    // distinguishable from the scheduled one by the actor recorded here.
    case B2bSyncRequested = 'integration.b2b_sync_requested';

    // Content and media.
    case MediaUploaded = 'admin.media_uploaded';
    case CatalogProductDeleted = 'catalog.product_deleted';
    case CatalogCategoryDeleted = 'catalog.category_deleted';
    case CatalogBrandDeleted = 'catalog.brand_deleted';
    case CmsContentDeleted = 'cms.content_deleted';

    /**
     * The resource kind an action is attributed to when the caller does not say.
     *
     * Every case is covered, which is the point: the match is exhaustive so that adding an
     * action without deciding its subject is a compile-time-visible omission rather than a row
     * that quietly lands with no subject and cannot be found by a later query.
     */
    public function subjectHint(): string
    {
        return match ($this) {
            self::LoginSucceeded, self::LoginFailed, self::LoggedOut => 'security_identity',
            self::SettingsUpdated => 'store_setting',
            self::OrderStateChanged => 'order',
            self::CustomerStatusChanged => 'customer',
            self::PaymentRetried, self::PaymentCancelled, self::PaymentRefunded => 'payment',
            self::ShipmentCreated, self::ShipmentHandedOver, self::ShipmentMarkedInTransit,
            self::ShipmentMarkedDelivered, self::ShipmentCancelled, self::ShipmentLabelRequested,
            self::ShipmentStatusRefreshed, self::ShipmentCreationRetried => 'shipment',
            self::ReturnRequested, self::ReturnApproved, self::ReturnRejected, self::ReturnReceived,
            self::ReturnRefundRecorded, self::ReturnWithdrawn => 'return',
            self::B2bSyncRequested => 'integration',
            self::MediaUploaded => 'media',
            self::CatalogProductDeleted, self::CatalogCategoryDeleted, self::CatalogBrandDeleted => 'catalog_item',
            self::CmsContentDeleted => 'cms_content',
        };
    }
}
