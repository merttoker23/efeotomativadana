<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Payment;
use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderState;
use App\Module\Order\OrderSummary;
use App\Module\Payment\PaymentState;
use App\Shared\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderSummaryTest extends TestCase
{
    #[DataProvider('paymentEligibility')]
    public function testPaymentRecoveryDependsOnOrderAndPaymentState(OrderState $orderState, ?PaymentState $paymentState, bool $expected): void
    {
        $order = $this->order($orderState);
        $payment = null === $paymentState ? null : $this->payment($order, $paymentState);

        self::assertSame($expected, OrderSummary::build($order, $payment, null)->canPay());
    }

    public static function paymentEligibility(): iterable
    {
        foreach (OrderState::cases() as $orderState) {
            foreach ([null, ...PaymentState::cases()] as $paymentState) {
                $open = in_array($orderState, [OrderState::Placed, OrderState::Confirmed], true);
                $unpaid = in_array($paymentState, [null, PaymentState::Pending, PaymentState::RequiresAction, PaymentState::Failed], true);
                yield $orderState->value.'/'.($paymentState?->value ?? 'missing') => [$orderState, $paymentState, $open && $unpaid];
            }
        }
    }

    public function testCapturedMoneyPreventsRecoveryEvenWhenStateIsPending(): void
    {
        $order = $this->order();
        $payment = $this->payment($order, PaymentState::Pending);
        (new \ReflectionProperty(Payment::class, 'capturedMinorAmount'))->setValue($payment, 100);

        $summary = OrderSummary::build($order, $payment, null);
        self::assertFalse($summary->canPay());
        self::assertSame('Ödeme alındı', $summary->paymentStatusLabel());
    }

    #[DataProvider('customerLabels')]
    public function testPaymentLabelsUseCustomerWording(PaymentState $state, string $label): void
    {
        $order = $this->order();
        self::assertSame($label, OrderSummary::build($order, $this->payment($order, $state), null)->paymentStatusLabel());
    }

    public static function customerLabels(): iterable
    {
        yield [PaymentState::Pending, 'Ödeme bekleniyor'];
        yield [PaymentState::RequiresAction, 'Ödeme bekleniyor'];
        yield [PaymentState::Failed, 'Ödeme başarısız'];
        yield [PaymentState::Succeeded, 'Ödendi'];
        yield [PaymentState::Cancelled, 'Ödeme iptal edildi'];
        yield [PaymentState::PartiallyRefunded, 'Kısmen iade edildi'];
        yield [PaymentState::Refunded, 'İade edildi'];
        yield [PaymentState::CapturedAmountMismatch, 'Ödeme alındı, tutar farklı'];
    }

    private function payment(CustomerOrder $order, PaymentState $state): Payment
    {
        $payment = Payment::start($order, 'paytr', $order->grandTotal(), new \DateTimeImmutable());
        // Projection tests cover persisted snapshots without invoking provider orchestration.
        (new \ReflectionProperty(Payment::class, 'state'))->setValue($payment, $state);

        return $payment;
    }

    private function order(OrderState $state = OrderState::Placed): CustomerOrder
    {
        $zero = Money::ofMinor(0, 'TRY');
        $total = Money::ofMinor(233_677, 'TRY');
        $order = new CustomerOrder('EOA-20261005-A1B2C3D4E5F6', new CustomerUser('summary@example.com', 'Efe', 'Yılmaz'), $total, $zero, $zero, $total, 'local_standard', 'Standart teslimat', 'gateway_checkout', 'Kredi kartı', new \DateTimeImmutable());
        (new \ReflectionProperty(CustomerOrder::class, 'state'))->setValue($order, $state);

        return $order;
    }
}
