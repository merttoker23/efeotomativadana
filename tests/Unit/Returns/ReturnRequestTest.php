<?php

declare(strict_types=1);

namespace App\Tests\Unit\Returns;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\ReturnRequest;
use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderState;
use App\Module\Returns\ReturnNumberGenerator;
use App\Module\Returns\ReturnState;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class ReturnRequestTest extends TestCase
{
    private const string NOW = '2026-09-28 10:00:00';

    public function testANewRequestStartsInRequestedAndRecordsItsFirstEvent(): void
    {
        $order = self::confirmedOrder();
        $requestedAt = self::at(self::NOW);

        $return = ReturnRequest::open($order, 'RET-20260928-ABCDEF012345', $requestedAt, 'Yanlış ürün gönderildi.', $order->customer()->getUserIdentifier());

        self::assertSame('RET-20260928-ABCDEF012345', $return->returnNumber());
        self::assertSame(ReturnState::Requested, $return->state());
        self::assertSame($order, $return->order());
        self::assertSame($order->customer(), $return->customer());
        self::assertSame('Yanlış ürün gönderildi.', $return->customerReason());
        self::assertNull($return->staffNote());
        self::assertNull($return->decidedAt());
        self::assertEquals($requestedAt, $return->createdAt());
        self::assertEquals($requestedAt, $return->updatedAt());
        self::assertTrue($return->isOpen());
        self::assertFalse($return->isClosed());

        $events = $return->events();
        self::assertCount(1, $events);
        self::assertSame(ReturnState::Requested, $events[0]->toState());
        self::assertSame('customer', $events[0]->source());
        self::assertEquals($requestedAt, $events[0]->occurredAt());
    }

    public function testAnItemSnapshotIsTakenFromTheOrderItemSoHistorySurvivesCatalogueChanges(): void
    {
        $order = self::confirmedOrder();
        $item = $order->items()[0];

        $return = self::openFor($order);
        $return->addItem($item, 2, 'Ürünü beğenmedim', Money::ofMinor(12_345, 'TRY'), $item->taxRateBasisPoints());

        $added = $return->items()[0];
        self::assertSame($item, $added->orderItem());
        self::assertSame($item->sku(), $added->sku());
        self::assertSame($item->productName(), $added->productName());
        self::assertSame(2, $added->quantity());
        self::assertSame('Ürünü beğenmedim', $added->reason());
        self::assertTrue($added->unitGross()->equals(Money::ofMinor(12_345, 'TRY')));
        self::assertSame($item->taxRateBasisPoints(), $added->taxRateBasisPoints());
    }

    public function testTheSameOrderItemCannotBeReturnedTwiceInOneRequest(): void
    {
        $order = self::confirmedOrder();
        $item = $order->items()[0];
        $return = self::openFor($order);

        $return->addItem($item, 1, 'Bir', Money::ofMinor(100, 'TRY'), $item->taxRateBasisPoints());

        $this->expectException(\DomainException::class);
        $return->addItem($item, 1, 'İki', Money::ofMinor(100, 'TRY'), $item->taxRateBasisPoints());
    }

    public function testAReturnQuantityMustBePositive(): void
    {
        $order = self::confirmedOrder();
        $return = self::openFor($order);

        $this->expectException(\DomainException::class);
        $return->addItem($order->items()[0], 0, 'Sıfır', Money::ofMinor(100, 'TRY'), 2000);
    }

    public function testARequestWithNoItemsCannotBeDecided(): void
    {
        $return = self::openFor(self::confirmedOrder());

        $this->expectException(\DomainException::class);
        $return->approve('Onaylandı.', self::later(), 'admin@example.com');
    }

    public function testApprovingRecordsTheDecisionTheEventTrailAndTheActor(): void
    {
        $return = self::openFor(self::confirmedOrder());
        self::assertTrue($return->addItemFromOrder($return->order()->items()[0], 1, 'Bozuk geldi'));

        $approvedAt = self::later();
        $return->approve('Depoda kontrol edilecek.', $approvedAt, 'admin@example.com');

        self::assertSame(ReturnState::Approved, $return->state());
        self::assertSame('Depoda kontrol edilecek.', $return->staffNote());
        self::assertEquals($approvedAt, $return->decidedAt());
        self::assertEquals($approvedAt, $return->updatedAt());
        // Approval ends the store's *decision*, not the return: the goods are still on their way
        // back. So the request stops being open without becoming terminal.
        self::assertFalse($return->isOpen());
        self::assertFalse($return->isClosed());
        self::assertNotNull($return->decidedAt());

        $events = $return->events();
        self::assertCount(2, $events);
        self::assertSame(ReturnState::Approved, $events[1]->toState());
        self::assertSame('store', $events[1]->source());
        self::assertSame('admin@example.com', $events[1]->actorEmail());
        self::assertEquals($approvedAt, $events[1]->occurredAt());
    }

    public function testRejectingRequiresAReasonTheCustomerCanBeShown(): void
    {
        $return = self::openFor(self::confirmedOrder());
        $return->addItemFromOrder($return->order()->items()[0], 1, 'Bozuk geldi');

        $this->expectException(\DomainException::class);
        $return->reject('   ', self::later(), 'admin@example.com');
    }

    public function testRejectingClosesTheRequestAndIsTerminal(): void
    {
        $return = self::openFor(self::confirmedOrder());
        $return->addItemFromOrder($return->order()->items()[0], 1, 'Bozuk geldi');

        $return->reject('Kullanılmış ürün iadesi kabul edilmez.', self::later(), 'admin@example.com');

        self::assertSame(ReturnState::Rejected, $return->state());
        self::assertTrue($return->state()->isTerminal());
        self::assertTrue($return->isClosed());

        $this->expectException(\DomainException::class);
        $return->markReceived(self::later(), 'admin@example.com');
    }

    public function testAnApprovedRequestIsMarkedReceivedThenRefunded(): void
    {
        $return = self::openFor(self::confirmedOrder());
        $return->addItemFromOrder($return->order()->items()[0], 1, 'Bozuk geldi');
        $return->approve('Kabul edildi.', self::later(), 'admin@example.com');

        $receivedAt = self::later();
        $return->markReceived($receivedAt, 'admin@example.com');
        self::assertSame(ReturnState::Received, $return->state());
        self::assertEquals($receivedAt, $return->receivedAt());
        self::assertTrue($return->isAwaitingRefund());

        $refundedAt = self::later();
        $return->markRefunded(45_000, 'TRY', 'paytr_ref_1', $refundedAt, 'admin@example.com');

        self::assertSame(ReturnState::Refunded, $return->state());
        self::assertTrue($return->refundMinorAmount()->equals(Money::ofMinor(45_000, 'TRY')));
        self::assertSame('paytr_ref_1', $return->refundReference());
        self::assertEquals($refundedAt, $return->refundedAt());
        self::assertTrue($return->state()->isTerminal());
        self::assertTrue($return->isClosed());
    }

    public function testARequestMayBeWithdrawnByTheCustomerOnlyWhileTheStoreHasNotDecided(): void
    {
        $return = self::openFor(self::confirmedOrder());
        $return->addItemFromOrder($return->order()->items()[0], 1, 'Bozuk geldi');

        $cancelledAt = self::later();
        $return->withdraw($cancelledAt, 'customer@example.com');

        self::assertSame(ReturnState::Withdrawn, $return->state());
        self::assertTrue($return->state()->isTerminal());
        self::assertEquals($cancelledAt, $return->cancelledAt());

        $this->expectException(\DomainException::class);
        $return->approve('Too late.', self::later(), 'admin@example.com');
    }

    public function testAnApprovedRequestCanStillBeWithdrawnBeforeTheGoodsArrive(): void
    {
        $return = self::openFor(self::confirmedOrder());
        $return->addItemFromOrder($return->order()->items()[0], 1, 'Bozuk geldi');
        $return->approve('Kabul.', self::later(), 'admin@example.com');

        $withdrawnAt = self::later();
        $return->withdraw($withdrawnAt, 'customer@example.com');

        self::assertSame(ReturnState::Withdrawn, $return->state());
        self::assertEquals($withdrawnAt, $return->cancelledAt());
    }

    public function testARequestCannotBeWithdrawnOnceTheGoodsHaveArrived(): void
    {
        $return = self::openFor(self::confirmedOrder());
        $return->addItemFromOrder($return->order()->items()[0], 1, 'Bozuk geldi');
        $return->approve('Kabul.', self::later(), 'admin@example.com');
        $return->markReceived(self::later(), 'admin@example.com');

        $this->expectException(\DomainException::class);
        $return->withdraw(self::later(), 'customer@example.com');
    }

    public function testARejectedRequestCannotBeWithdrawn(): void
    {
        $return = self::openFor(self::confirmedOrder());
        $return->addItemFromOrder($return->order()->items()[0], 1, 'Bozuk geldi');
        $return->reject('Kullanılmış ürün.', self::later(), 'admin@example.com');

        $this->expectException(\DomainException::class);
        $return->withdraw(self::later(), 'customer@example.com');
    }

    public function testTheEventTrailIsAppendOnlyAndEveryRowIsStampedFromTheTransitionNotTheCreation(): void
    {
        $return = self::openFor(self::confirmedOrder());
        $return->addItemFromOrder($return->order()->items()[0], 1, 'Bozuk geldi');
        $return->approve('Kabul.', self::later(), 'admin@example.com');
        $return->markReceived(self::later(), 'admin@example.com');
        $return->markRefunded(100, 'TRY', 'ref-1', self::later(), 'admin@example.com');

        $occurredAt = array_map(static fn ($event) => $event->occurredAt()->getTimestamp(), $return->events());
        $sorted = $occurredAt;
        sort($sorted);

        self::assertSame($sorted, $occurredAt, 'Return events must be recorded in chronological order.');
        self::assertCount(4, $return->events());
    }

    public function testTotalReturnedValueIsTheSumOfItsItems(): void
    {
        $order = self::confirmedOrder();
        $return = self::openFor($order);
        $return->addItemFromOrder($order->items()[0], 2, 'Bozuk geldi');
        $return->addItemFromOrder($order->items()[1], 1, 'Yanlış ürün');

        // 2 x 3.000,00 + 1 x 3.000,00. Taken from the order item's own unit price, so the customer
        // is told what the store recorded rather than what the catalogue says today.
        self::assertTrue($return->totalValue()->equals(Money::ofMinor(9_000, 'TRY')));
    }

    public function testAReturnNumberIsGeneratedInTheSameShapeAsAnOrderNumber(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable('2026-09-28 10:00:00', new \DateTimeZone('UTC')); }
        };

        $number = (new ReturnNumberGenerator($clock))->generate();

        self::assertMatchesRegularExpression('/^RET-\d{8}-[0-9A-F]{12}$/', $number);
    }

    public function testTwoGeneratedReturnNumbersDiffer(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable('2026-09-28 10:00:00', new \DateTimeZone('UTC')); }
        };
        $generator = new ReturnNumberGenerator($clock);

        self::assertNotSame($generator->generate(), $generator->generate());
    }

    public function testARequestCanBeOpenedForAConfirmedOrACompletedOrder(): void
    {
        // The aggregate records what it is handed; which orders may be returned is the
        // application service's policy, so it is tested there rather than duplicated here.
        $completed = self::confirmedOrder();
        $completed->transitionTo(OrderState::Completed);

        self::assertSame(ReturnState::Requested, ReturnRequest::open($completed, 'RET-20260928-AAAAAAAAAAAA', self::at(self::NOW), 'İyi', 'c@example.com')->state());
    }

    public function testAReturnRequestExposesTheOrderNumberForTemplatesAndMail(): void
    {
        $order = self::confirmedOrder();
        $return = self::openFor($order);

        self::assertSame($order->orderNumber(), $return->orderNumber());
        self::assertSame($order->customerEmail(), $return->customerEmail());
        self::assertSame($order->customerName(), $return->customerName());
    }

    public function testTheReturnNumberMustBeWellFormed(): void
    {
        $this->expectException(\DomainException::class);
        ReturnRequest::open(self::confirmedOrder(), 'not-a-return-number', self::at(self::NOW), 'İyi', 'c@example.com');
    }

    private static function openFor(CustomerOrder $order): ReturnRequest
    {
        return ReturnRequest::open($order, 'RET-20260928-ABCDEF012345', self::at(self::NOW), 'Bozuk geldi', $order->customer()->getUserIdentifier());
    }

    private static function at(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private static function later(): \DateTimeImmutable
    {
        return self::at('2026-09-28 12:00:00');
    }

    private static function confirmedOrder(): CustomerOrder
    {
        $customer = new CustomerUser('customer@example.com', 'Efe', 'Yılmaz');
        $order = new CustomerOrder(
            'EOA-20260928-ABCDEF012345',
            $customer,
            Money::ofMinor(12_000, 'TRY'),
            Money::ofMinor(2_000, 'TRY'),
            Money::ofMinor(0, 'TRY'),
            Money::ofMinor(12_000, 'TRY'),
            'local_standard',
            'Yerel standart teslimat',
            'gateway_checkout',
            'Kredi kartı',
            self::at(self::NOW),
        );
        $order->addItem(null, 'SKU-1', 'Filtre', 3, Money::ofMinor(3_000, 'TRY'), 2000, Money::ofMinor(7_500, 'TRY'), Money::ofMinor(1_500, 'TRY'), Money::ofMinor(9_000, 'TRY'));
        $order->addItem(null, 'SKU-2', 'Yağ', 1, Money::ofMinor(3_000, 'TRY'), 2000, Money::ofMinor(2_500, 'TRY'), Money::ofMinor(500, 'TRY'), Money::ofMinor(3_000, 'TRY'));
        $order->addAddress(OrderAddressRole::Shipping, 'Efe Yılmaz', '05320000000', 'Atatürk Caddesi 1', null, 'Çukurova', 'Adana', '01170', 'TR');
        $order->addAddress(OrderAddressRole::Billing, 'Efe Yılmaz', '05320000000', 'Atatürk Caddesi 1', null, 'Çukurova', 'Adana', '01170', 'TR');
        $order->sealSnapshots();
        $order->transitionTo(OrderState::Confirmed);

        return $order;
    }
}
