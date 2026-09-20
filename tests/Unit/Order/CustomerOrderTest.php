<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\CustomerOrder;
use App\Entity\Customer\CustomerUser;
use App\Module\Checkout\LocalManualPaymentOption;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderNumberGenerator;
use App\Module\Order\OrderState;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class CustomerOrderTest extends TestCase
{
    public function testOrderNumberContainsUtcDateAndUnpredictableSuffix(): void
    {
        $number = (new OrderNumberGenerator(new MockClock('2026-09-20 23:00:00 UTC')))->generate();

        self::assertMatchesRegularExpression('/^EOA-20260920-[0-9A-F]{12}$/', $number);
    }

    public function testLocalManualPaymentIsExplicitlyDisabledInProduction(): void
    {
        $option = new LocalManualPaymentOption('prod');

        self::assertFalse($option->available());
        self::assertFalse($option->productionReady());
        self::assertStringContainsString('üretim dışı', $option->label());
    }

    public function testItKeepsExactLineAddressAndMethodSnapshots(): void
    {
        $customer = new CustomerUser('buyer@example.com', 'Efe', 'Yılmaz');
        $customer->setProfile('Efe', 'Yılmaz', '05000000000');
        $product = new Product('SKU-08', 'Fren Balatası', 'fren-balatasi');
        $order = new CustomerOrder(
            'EOA-20260920-A1B2C3D4E5F6',
            $customer,
            Money::ofMinor(24_000, 'TRY'),
            Money::ofMinor(4_000, 'TRY'),
            Money::ofMinor(0, 'TRY'),
            Money::ofMinor(24_000, 'TRY'),
            'local_standard',
            'Standart teslimat',
            'local_manual',
            'Yerel manuel doğrulama (üretim dışı)',
            new \DateTimeImmutable('2026-09-20 12:00:00 UTC'),
        );
        $order->addItem(
            $product,
            'SKU-08',
            'Fren Balatası',
            2,
            Money::ofMinor(12_000, 'TRY'),
            2_000,
            Money::ofMinor(20_000, 'TRY'),
            Money::ofMinor(4_000, 'TRY'),
            Money::ofMinor(24_000, 'TRY'),
        );
        $order->addAddress(OrderAddressRole::Shipping, 'Efe Yılmaz', '05000000000', 'Atatürk Cad. 1', null, 'Seyhan', 'Adana', '01000', 'TR');
        $order->addAddress(OrderAddressRole::Billing, 'Efe Yılmaz', '05000000000', 'Fatura Cad. 2', 'Kat 3', 'Çukurova', 'Adana', null, 'TR');
        $order->sealSnapshots();
        $customer->setProfile('Değişen', 'Müşteri', '05550000000');

        self::assertSame(OrderState::Placed, $order->state());
        self::assertSame('buyer@example.com', $order->customerEmail());
        self::assertSame('Efe Yılmaz', $order->customerName());
        self::assertSame('05000000000', $order->customerPhone());
        self::assertSame(24_000, $order->grandTotal()->minorAmount());
        self::assertSame('SKU-08', $order->items()[0]->sku());
        self::assertSame('Fren Balatası', $order->items()[0]->productName());
        self::assertSame(4_000, $order->items()[0]->taxAmount()->minorAmount());
        self::assertSame('Atatürk Cad. 1', $order->address(OrderAddressRole::Shipping)?->addressLine1());
        self::assertSame('Fatura Cad. 2', $order->address(OrderAddressRole::Billing)?->addressLine1());
        self::assertSame('local_manual', $order->paymentOptionKey());
    }

    public function testSealedSnapshotsCannotBeChanged(): void
    {
        $order = $this->order();

        $this->expectException(\DomainException::class);
        $order->addItem(null, 'LATE-SKU', 'Late item', 1, Money::ofMinor(0, 'TRY'), 0, Money::ofMinor(0, 'TRY'), Money::ofMinor(0, 'TRY'), Money::ofMinor(0, 'TRY'));
    }

    public function testIncompleteOrderCannotBeSealed(): void
    {
        $zero = Money::ofMinor(0, 'TRY');
        $order = new CustomerOrder(
            'EOA-20260920-A1B2C3D4E5F6',
            new CustomerUser('buyer@example.com', 'Efe', 'Yılmaz'),
            $zero,
            $zero,
            $zero,
            $zero,
            'local_standard',
            'Standart teslimat',
            'local_manual',
            'Yerel manuel doğrulama (üretim dışı)',
            new \DateTimeImmutable('2026-09-20 12:00:00 UTC'),
        );

        $this->expectException(\DomainException::class);
        $order->sealSnapshots();
    }

    public function testItemRejectsTaxRateOutsideCanonicalRange(): void
    {
        $order = $this->unsealedZeroOrder();

        $this->expectException(\InvalidArgumentException::class);
        $order->addItem(null, 'SKU-08', 'Fren Balatası', 1, Money::ofMinor(0, 'TRY'), 10_001, Money::ofMinor(0, 'TRY'), Money::ofMinor(0, 'TRY'), Money::ofMinor(0, 'TRY'));
    }

    public function testItRejectsInconsistentExactTotals(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CustomerOrder(
            'EOA-20260920-A1B2C3D4E5F6',
            new CustomerUser('buyer@example.com', 'Efe', 'Yılmaz'),
            Money::ofMinor(24_000, 'TRY'),
            Money::ofMinor(4_000, 'TRY'),
            Money::ofMinor(500, 'TRY'),
            Money::ofMinor(24_000, 'TRY'),
            'local_standard',
            'Standart teslimat',
            'local_manual',
            'Yerel manuel doğrulama (üretim dışı)',
            new \DateTimeImmutable('2026-09-20 12:00:00 UTC'),
        );
    }

    public function testItRejectsDuplicateAddressRoles(): void
    {
        $order = $this->unsealedZeroOrder();
        $order->addAddress(OrderAddressRole::Shipping, 'Efe Yılmaz', '05000000000', 'Cadde 1', null, 'Seyhan', 'Adana', null, 'TR');

        $this->expectException(\DomainException::class);
        $order->addAddress(OrderAddressRole::Shipping, 'Başka Alıcı', '05000000001', 'Cadde 2', null, 'Seyhan', 'Adana', null, 'TR');
    }

    public function testLifecycleOnlyAllowsExplicitTransitions(): void
    {
        $order = $this->order();
        $order->transitionTo(OrderState::Confirmed);
        $order->transitionTo(OrderState::Completed);

        self::assertSame(OrderState::Completed, $order->state());

        $this->expectException(\DomainException::class);
        $order->transitionTo(OrderState::Cancelled);
    }

    public function testRepeatedLifecycleTransitionFailsDeterministically(): void
    {
        $order = $this->order();

        $this->expectException(\DomainException::class);
        $order->transitionTo(OrderState::Placed);
    }

    private function order(): CustomerOrder
    {
        $order = $this->unsealedZeroOrder();
        $zero = Money::ofMinor(0, 'TRY');
        $order->addItem(null, 'ZERO-SKU', 'Zero-priced item', 1, $zero, 0, $zero, $zero, $zero);
        $order->addAddress(OrderAddressRole::Shipping, 'Efe Yılmaz', '05000000000', 'Cadde 1', null, 'Seyhan', 'Adana', null, 'TR');
        $order->addAddress(OrderAddressRole::Billing, 'Efe Yılmaz', '05000000000', 'Cadde 1', null, 'Seyhan', 'Adana', null, 'TR');
        $order->sealSnapshots();

        return $order;
    }

    private function unsealedZeroOrder(): CustomerOrder
    {
        return new CustomerOrder(
            'EOA-20260920-A1B2C3D4E5F6',
            new CustomerUser('buyer@example.com', 'Efe', 'Yılmaz'),
            Money::ofMinor(0, 'TRY'),
            Money::ofMinor(0, 'TRY'),
            Money::ofMinor(0, 'TRY'),
            Money::ofMinor(0, 'TRY'),
            'local_standard',
            'Standart teslimat',
            'local_manual',
            'Yerel manuel doğrulama (üretim dışı)',
            new \DateTimeImmutable('2026-09-20 12:00:00 UTC'),
        );
    }
}
