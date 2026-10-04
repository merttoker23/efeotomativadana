<?php

declare(strict_types=1);

namespace App\Module\Checkout;

use App\Module\Order\OrderNote;

final readonly class CheckoutSelection
{
    public ?string $orderNote;

    public function __construct(
        public int $shippingAddressId,
        public int $billingAddressId,
        public string $shippingOptionKey,
        public string $paymentOptionKey,
        ?string $orderNote = null,
    ) {
        if ($shippingAddressId < 1 || $billingAddressId < 1) {
            throw new CheckoutViolation('Lütfen geçerli teslimat ve fatura adresleri seçin.');
        }
        if ('' === trim($shippingOptionKey) || '' === trim($paymentOptionKey)) {
            throw new CheckoutViolation('Lütfen teslimat ve ödeme yöntemlerini seçin.');
        }
        try {
            $this->orderNote = OrderNote::normalize($orderNote);
        } catch (\InvalidArgumentException $exception) {
            throw new CheckoutViolation($exception->getMessage(), previous: $exception);
        }
    }
}
