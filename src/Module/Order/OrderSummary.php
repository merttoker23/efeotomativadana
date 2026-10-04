<?php

declare(strict_types=1);

namespace App\Module\Order;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Payment;
use App\Entity\Commerce\PaymentState;
use App\Entity\Commerce\Shipment;
use App\Module\Payment\PaymentState as PaymentStateAlias;
use App\Module\Shipping\ShipmentTrackingView;
use App\Shared\Money\Money;

/**
 * Everything one order page shows about money and the parcel, assembled from the aggregates.
 *
 * The order, the payment and the shipment are three separate aggregates and this is the one place
 * they are read together. A template is handed a finished summary rather than three optional
 * objects, because "the parcel" being null and "the parcel is empty" are different states and a
 * template cannot tell them apart.
 *
 * Two rules are enforced here rather than trusted to a template:
 *
 * - the payment's method label comes from the payment, and a failed payment is described in the
 *   customer's own words. A provider's raw failure text is written for an operator and quotes
 *   provider internals, so {@see $paymentStatusLabel} never carries it;
 * - the tracking summary is {@see ShipmentTrackingView}, PHASE 16's projection, which by
 *   construction contains nothing a provider URL could turn into a link.
 */
final readonly class OrderSummary
{
    /**
     * @param list<OrderItemLine> $lines
     */
    private function __construct(
        private CustomerOrder $order,
        private array $lines,
        private ?string $paymentState,
        private ?string $paymentStatusLabel,
        private ?string $paymentMethodLabel,
        private ?Money $capturedAmount,
        private ?Money $refundedAmount,
        private ?ShipmentTrackingView $tracking,
    ) {
    }

    /** @param array<int, string|null> $imagePaths Current images only; all other values use the sealed snapshot. */
    public static function build(CustomerOrder $order, ?Payment $payment, ?Shipment $shipment, array $imagePaths = []): self
    {
        $lines = [];
        foreach ($order->items() as $item) {
            $lines[] = OrderItemLine::fromItem($item, $imagePaths[$item->id()] ?? null);
        }

        return new self(
            $order,
            $lines,
            null === $payment ? null : $payment->state()->value,
            null === $payment ? null : self::describePayment($payment),
            null === $payment ? null : $payment->methodLabel(),
            null === $payment ? null : $payment->capturedAmount(),
            null === $payment ? null : $payment->refundedAmount(),
            null === $shipment ? null : ShipmentTrackingView::forShipment($shipment),
        );
    }

    /**
     * What the customer is told about their money.
     *
     * Deliberately never the provider's own wording. A declined card that says "merchant_oid ile
     * basarili odeme bulunamadi" on an order page teaches the customer nothing and leaks an
     * internal to whoever reads the page over their shoulder.
     */
    private static function describePayment(Payment $payment): string
    {
        $captured = $payment->capturedAmount()->minorAmount() > 0;

        return match ($payment->state()) {
            PaymentStateAlias::Succeeded => 'Ödendi',
            PaymentStateAlias::PartiallyRefunded => 'Kısmen iade edildi',
            PaymentStateAlias::Refunded => 'İade edildi',
            PaymentStateAlias::CapturedAmountMismatch => 'Ödeme alındı, tutar farklı',
            PaymentStateAlias::RequiresAction => 'Ödemeniz bekleniyor',
            PaymentStateAlias::Pending => $captured ? 'Ödeme alındı' : 'Ödeme bekleniyor',
            PaymentStateAlias::Failed => 'Ödeme başarısız',
            PaymentStateAlias::Cancelled => 'Ödeme iptal edildi',
        };
    }

    public function order(): CustomerOrder { return $this->order; }
    public function orderNumber(): string { return $this->order->orderNumber(); }
    public function state(): OrderState { return $this->order->state(); }

    /**
     * The customer's wording for the state, resolved here.
     *
     * A template must never be handed the enum itself: rendering an enum is a type error, and the
     * fix belongs in the projection rather than in a `|default` in every template that forgets.
     */
    public function stateLabel(): string { return OrderStateLabel::for($this->order->state()); }

    /** @return list<OrderItemLine> */
    public function lines(): array { return $this->lines; }

    public function quantity(): int
    {
        return array_sum(array_map(static fn (OrderItemLine $line): int => $line->quantity(), $this->lines));
    }

    public function hasPayment(): bool { return null !== $this->paymentState; }
    public function paymentState(): ?string { return $this->paymentState; }
    public function paymentStatusLabel(): ?string { return $this->paymentStatusLabel; }
    public function paymentMethodLabel(): ?string { return $this->paymentMethodLabel; }
    public function capturedAmount(): ?Money { return $this->capturedAmount; }
    public function refundedAmount(): ?Money { return $this->refundedAmount; }
    public function hasRefundedAmount(): bool { return null !== $this->refundedAmount && $this->refundedAmount->minorAmount() > 0; }

    public function hasTracking(): bool { return null !== $this->tracking; }
    public function tracking(): ?ShipmentTrackingView { return $this->tracking; }
}
