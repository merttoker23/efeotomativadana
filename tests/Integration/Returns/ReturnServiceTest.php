<?php

declare(strict_types=1);

namespace App\Tests\Integration\Returns;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\ReturnRequest;
use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderState;
use App\Module\Returns\ReturnIneligibility;
use App\Module\Returns\ReturnLine;
use App\Module\Returns\ReturnLineDuplicated;
use App\Module\Returns\ReturnPolicy;
use App\Module\Returns\ReturnService;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The rules a return request is allowed to break, proved against a real database.
 *
 * These are the assertions the phase names explicitly: ownership, the quantity ceiling, and the
 * fact that a replayed request does not consume the same goods twice.
 */
final class ReturnServiceTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private ReturnService $returns;
    private ReturnPolicy $policy;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $manager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->entityManager = $manager;
        $service = self::getContainer()->get(ReturnService::class);
        self::assertInstanceOf(ReturnService::class, $service);
        $this->returns = $service;
        $policy = self::getContainer()->get(ReturnPolicy::class);
        self::assertInstanceOf(ReturnPolicy::class, $policy);
        $this->policy = $policy;
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testACustomerCanRaiseAReturnAgainstTheirOwnDeliveredOrder(): void
    {
        $customer = $this->customer('owner@example.com');
        $order = $this->confirmedOrder($customer);
        $item = $order->items()[0];

        $return = $this->returns->request($customer, $order->orderNumber(), [new ReturnLine($item, 2, 'Filtre bozuk geldi.')], 'Paket yırtık ulaştı.');

        self::assertNotNull($return->id());
        self::assertSame($order->orderNumber(), $return->orderNumber());
        self::assertSame($customer->id(), $return->customer()->id());
        self::assertCount(1, $return->items());
        self::assertSame(2, $return->items()[0]->quantity());
        self::assertCount(1, $return->events());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_return_request WHERE return_number = ?', [$return->returnNumber()]));
    }

    public function testOneCustomerCannotRaiseAReturnAgainstAnotherCustomersOrderByGuessingItsNumber(): void
    {
        $owner = $this->customer('owner@example.com');
        $stranger = $this->customer('stranger@example.com');
        $order = $this->confirmedOrder($owner);

        $this->expectException(\App\Module\Order\OrderNotFound::class);

        $this->returns->request($stranger, $order->orderNumber(), [new ReturnLine($order->items()[0], 1, 'Bozuk.')], 'Bozuk.');
    }

    public function testReturnQuantityCannotExceedWhatWasPurchased(): void
    {
        $customer = $this->customer('owner@example.com');
        $order = $this->confirmedOrder($customer, quantity: 2);
        $item = $order->items()[0];

        $this->expectException(\App\Module\Returns\ReturnQuantityExceeded::class);

        $this->returns->request($customer, $order->orderNumber(), [new ReturnLine($item, 3, 'Bozuk.')], 'Bozuk.');
    }

    public function testASecondRequestCannotConsumeQuantityAnOpenRequestAlreadyHolds(): void
    {
        $customer = $this->customer('owner@example.com');
        $order = $this->confirmedOrder($customer, quantity: 3);
        $item = $order->items()[0];

        $this->returns->request($customer, $order->orderNumber(), [new ReturnLine($item, 2, 'Bozuk.')], 'Bozuk.');

        $this->expectException(\App\Module\Returns\ReturnQuantityExceeded::class);
        $this->returns->request($customer, $order->orderNumber(), [new ReturnLine($item, 2, 'Yine bozuk.')], 'Yine bozuk.');
    }

    public function testARejectedRequestReleasesTheQuantityItWasHolding(): void
    {
        $customer = $this->customer('owner@example.com');
        $order = $this->confirmedOrder($customer, quantity: 3);
        $item = $order->items()[0];

        $first = $this->returns->request($customer, $order->orderNumber(), [new ReturnLine($item, 3, 'Bozuk.')], 'Bozuk.');
        $this->returns->reject($first, 'Kullanılmış ürün.', 'admin@example.com');

        // The same three units are now returnable again, because the store said no to the first ask.
        $second = $this->returns->request($customer, $order->orderNumber(), [new ReturnLine($item, 3, 'Farklı bir sebeple.')], 'Farklı bir sebeple.');

        self::assertSame(3, $second->items()[0]->quantity());
    }

    public function testAWithdrawnRequestAlsoReleasesItsQuantity(): void
    {
        $customer = $this->customer('owner@example.com');
        $order = $this->confirmedOrder($customer, quantity: 2);
        $item = $order->items()[0];

        $first = $this->returns->request($customer, $order->orderNumber(), [new ReturnLine($item, 2, 'Fikrimi değiştirdim.')], 'Fikrimi değiştirdim.');
        $this->returns->withdraw($first);

        $second = $this->returns->request($customer, $order->orderNumber(), [new ReturnLine($item, 2, 'Yine.')], 'Yine.');

        self::assertSame(2, $second->items()[0]->quantity());
    }

    public function testAnOrderLineFromAnotherOrderCannotBeSmuggledIntoARequest(): void
    {
        $customer = $this->customer('owner@example.com');
        $order = $this->confirmedOrder($customer, quantity: 2);
        $other = $this->confirmedOrder($customer, quantity: 2);
        $foreignItem = $other->items()[0];

        $this->expectException(\App\Module\Returns\ReturnLineNotInOrder::class);

        $this->returns->request($customer, $order->orderNumber(), [new ReturnLine($foreignItem, 1, 'Bozuk.')], 'Bozuk.');
    }

    public function testARequestWithNoLinesIsRefusedRatherThanStoredEmpty(): void
    {
        $customer = $this->customer('owner@example.com');
        $order = $this->confirmedOrder($customer);

        $this->expectException(\DomainException::class);

        $this->returns->request($customer, $order->orderNumber(), [], 'Sebep.');
    }
    public function testTheSameOrderLineCannotBeListedTwiceInOneRequest(): void
    {
        $customer = $this->customer('owner@example.com');
        $order = $this->confirmedOrder($customer, quantity: 4);
        $item = $order->items()[0];

        $this->expectException(ReturnLineDuplicated::class);

        $this->returns->request($customer, $order->orderNumber(), [new ReturnLine($item, 1, 'Bir.'), new ReturnLine($item, 1, 'İki.')], 'Sebep.');
    }

    public function testAnUnconfirmedOrderIsNotReturnable(): void
    {
        $customer = $this->customer('owner@example.com');
        $order = $this->placedOrder($customer);

        $this->expectException(\App\Module\Returns\OrderNotReturnable::class);

        $this->returns->request($customer, $order->orderNumber(), [new ReturnLine($order->items()[0], 1, 'Bozuk.')], 'Bozuk.');
    }

    public function testACancelledOrderIsNotReturnable(): void
    {
        $customer = $this->customer('owner@example.com');
        $order = $this->confirmedOrder($customer);
        $order->transitionTo(OrderState::Cancelled);
        $this->entityManager->flush();

        $this->expectException(\App\Module\Returns\OrderNotReturnable::class);

        $this->returns->request($customer, $order->orderNumber(), [new ReturnLine($order->items()[0], 1, 'Bozuk.')], 'Bozuk.');
    }

    public function testAnOrderOlderThanTheReturnWindowIsNotReturnable(): void
    {
        $customer = $this->customer('owner@example.com');
        $order = $this->confirmedOrder($customer, placedAt: new \DateTimeImmutable('2026-01-01 10:00:00'));

        $this->expectException(\App\Module\Returns\OrderNotReturnable::class);

        $this->returns->request($customer, $order->orderNumber(), [new ReturnLine($order->items()[0], 1, 'Bozuk.')], 'Bozuk.');
    }

    public function testTheReturnWindowIsFourteenDaysAndItsBoundaryIsInclusive(): void
    {
        $placedAt = new \DateTimeImmutable('2026-09-14 10:00:00');
        $customer = $this->customer('owner@example.com');
        $order = $this->confirmedOrder($customer, placedAt: $placedAt);

        self::assertSame(14, ReturnPolicy::WINDOW_DAYS);
        self::assertTrue($this->policy->isWithinWindow($order, $placedAt->modify('+14 days')));
        self::assertFalse($this->policy->isWithinWindow($order, $placedAt->modify('+14 days +1 second')));
    }

    public function testThePolicyExplainsWhyAnOrderCannotBeReturnedRatherThanOnlyRefusing(): void
    {
        $customer = $this->customer('owner@example.com');
        $placed = $this->placedOrder($customer);

        self::assertSame(ReturnIneligibility::OrderNotSettled, $this->policy->whyNotReturnable($placed, new \DateTimeImmutable('2026-09-28 10:00:00')));
        self::assertNull($this->policy->whyNotReturnable($this->confirmedOrder($customer, placedAt: new \DateTimeImmutable('2026-09-28 09:00:00')), new \DateTimeImmutable('2026-09-28 10:00:00')));
    }

    public function testThePolicyReportsTheReasonInTurkishForTheCustomerScreen(): void
    {
        self::assertSame('Sipariş henüz ödemeniz onaylanmadı.', ReturnIneligibility::OrderNotSettled->customerMessage());
        self::assertNotSame('', ReturnIneligibility::ReturnWindowExpired->customerMessage());
        self::assertNotSame('', ReturnIneligibility::NothingLeftToReturn->customerMessage());
    }

    public function testAnExpiredOrderReportsTheWindowAndNotTheSettlement(): void
    {
        $customer = $this->customer('owner@example.com');
        $order = $this->confirmedOrder($customer, placedAt: new \DateTimeImmutable('2026-01-01 10:00:00'));

        self::assertSame(ReturnIneligibility::ReturnWindowExpired, $this->policy->whyNotReturnable($order, new \DateTimeImmutable('2026-09-28 10:00:00')));
    }

    public function testReturnableQuantityIsWhatIsBoughtLessWhatIsAlreadyClaimed(): void
    {
        $customer = $this->customer('owner@example.com');
        $order = $this->confirmedOrder($customer, quantity: 5);
        $item = $order->items()[0];

        self::assertSame(5, $this->policy->returnableQuantityFor($order, $item, []));

        $this->returns->request($customer, $order->orderNumber(), [new ReturnLine($item, 2, 'Bozuk.')], 'Bozuk.');

        self::assertSame(3, $this->policy->returnableQuantityFor($order, $item, $this->returns->claimedQuantities($order)));
    }

    public function testTheWholeLifecycleIsAuditableAndEveryRowSurvivesAReload(): void
    {
        $customer = $this->customer('lifecycle@example.com');
        $order = $this->confirmedOrder($customer);
        $item = $order->items()[0];

        $return = $this->returns->request($customer, $order->orderNumber(), [new ReturnLine($item, 1, 'Bozuk geldi.')], 'Bozuk geldi.');
        $this->returns->approve($return, 'Depoya alındı.', 'admin@example.com');
        $this->returns->markReceived($return, 'admin@example.com');
        $this->returns->recordRefund($return, 12_345, 'TRY', 'paytr_ref_1', 'admin@example.com');

        $this->entityManager->clear();
        $reloaded = self::getContainer()->get('doctrine')->getManager()->getRepository(ReturnRequest::class)->findOneBy(['returnNumber' => $return->returnNumber()]);
        self::assertInstanceOf(ReturnRequest::class, $reloaded);

        $events = $reloaded->events();
        self::assertCount(4, $events);
        self::assertSame(['requested', 'approved', 'received', 'refunded'], array_map(static fn ($event) => $event->toState()->value, $events));
        self::assertSame('paytr_ref_1', $reloaded->refundReference());
        self::assertTrue($reloaded->refundMinorAmount()->equals(Money::ofMinor(12_345, 'TRY')));

        // The order itself is untouched by any of it: a return is a conversation, not a state change.
        $this->entityManager->clear();
        $reloadedOrder = self::getContainer()->get('doctrine')->getManager()->find(CustomerOrder::class, $order->id());
        self::assertInstanceOf(CustomerOrder::class, $reloadedOrder);
        self::assertSame(OrderState::Confirmed, $reloadedOrder->state());
    }

    public function testAnApprovedReturnCanBeMarkedReceivedAndRefundedAndAReplayIsRefused(): void
    {
        $customer = $this->customer('replay@example.com');
        $order = $this->confirmedOrder($customer);
        $return = $this->returns->request($customer, $order->orderNumber(), [new ReturnLine($order->items()[0], 1, 'Bozuk.')], 'Bozuk.');
        $this->returns->approve($return, 'Kabul.', 'admin@example.com');
        $this->returns->markReceived($return, 'admin@example.com');
        $this->returns->recordRefund($return, 1_000, 'TRY', 'ref-1', 'admin@example.com');

        $this->expectException(\DomainException::class);
        $this->returns->markReceived($return, 'admin@example.com');
    }

    public function testAReturnCannotBeApprovedBeforeItHasLines(): void
    {
        $customer = $this->customer('empty@example.com');
        $order = $this->confirmedOrder($customer);
        $return = ReturnRequest::open($order, 'RET-20260928-DEADBEEFCAFE', new \DateTimeImmutable('2026-09-28 10:00:00'), 'Sebep.');
        $this->entityManager->persist($return);
        $this->entityManager->flush();

        $this->expectException(\DomainException::class);
        $this->returns->approve($return, 'Kabul.', 'admin@example.com');
    }

    public function testACustomerSeesOnlyTheirOwnRequests(): void
    {
        $owner = $this->customer('mine@example.com');
        $stranger = $this->customer('theirs@example.com');
        $order = $this->confirmedOrder($owner);
        $return = $this->returns->request($owner, $order->orderNumber(), [new ReturnLine($order->items()[0], 1, 'Bozuk.')], 'Bozuk.');

        self::assertNotNull($this->returns->findForCustomer($return->returnNumber(), $owner));
        self::assertNull($this->returns->findForCustomer($return->returnNumber(), $stranger));
    }

    public function testACustomerCanWithdrawTheirOwnOpenRequest(): void
    {
        $customer = $this->customer('withdraw@example.com');
        $order = $this->confirmedOrder($customer);
        $return = $this->returns->request($customer, $order->orderNumber(), [new ReturnLine($order->items()[0], 1, 'Fikrimi değiştirdim.')], 'Fikrimi değiştirdim.');

        $this->returns->withdraw($return);

        self::assertTrue($return->isClosed());
    }

    public function testTheCustomerPageIsPaginatedAndScopedToOneCustomer(): void
    {
        $first = $this->customer('page-a@example.com');
        $second = $this->customer('page-b@example.com');
        $orderA = $this->confirmedOrder($first, placedAt: new \DateTimeImmutable('2026-09-27 10:00:00'));
        $orderB = $this->confirmedOrder($second, placedAt: new \DateTimeImmutable('2026-09-28 10:00:00'));
        $this->returns->request($first, $orderA->orderNumber(), [new ReturnLine($orderA->items()[0], 1, 'Bozuk.')], 'Bozuk.');
        $this->returns->request($second, $orderB->orderNumber(), [new ReturnLine($orderB->items()[0], 1, 'Bozuk.')], 'Bozuk.');

        $page = $this->returns->pageForCustomer($first, 1, 10);

        self::assertSame(1, $page->total);
        self::assertCount(1, $page->items);
        self::assertSame($first->id(), $page->items[0]->customer()->id());
    }

    public function testEveryRequestStateIsCoveredByTheStateEnumSoAnAdminFilterCannotSeeAnUnknownValue(): void
    {
        $values = array_map(static fn ($state) => $state->value, \App\Module\Returns\ReturnState::cases());
        sort($values);

        self::assertSame(['approved', 'received', 'refunded', 'rejected', 'requested', 'withdrawn'], $values);
    }

    private function customer(string $email): CustomerUser
    {
        $customer = new CustomerUser($email, 'Efe', 'Yılmaz');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function confirmedOrder(CustomerUser $customer, int $quantity = 3, ?\DateTimeImmutable $placedAt = null): CustomerOrder
    {
        $order = $this->placedOrder($customer, $quantity, $placedAt);
        $order->transitionTo(OrderState::Confirmed);
        $this->entityManager->flush();

        return $order;
    }

    private function placedOrder(CustomerUser $customer, int $quantity = 3, ?\DateTimeImmutable $placedAt = null): CustomerOrder
    {
        $at = $placedAt ?? new \DateTimeImmutable('2026-09-28 09:00:00');
        $lineGross = 3_000 * $quantity;
        $order = new CustomerOrder(
            sprintf('EOA-%s-%s', $at->format('Ymd'), strtoupper(bin2hex(random_bytes(6)))),
            $customer,
            Money::ofMinor($lineGross, 'TRY'),
            Money::ofMinor(500 * $quantity, 'TRY'),
            Money::ofMinor(0, 'TRY'),
            Money::ofMinor($lineGross, 'TRY'),
            'local_standard',
            'Yerel standart teslimat',
            'gateway_checkout',
            'Kredi kartı',
            $at,
        );
        $order->addItem(null, 'SKU-'.bin2hex(random_bytes(3)), 'Filtre', $quantity, Money::ofMinor(3_000, 'TRY'), 2000, Money::ofMinor(2_500 * $quantity, 'TRY'), Money::ofMinor(500 * $quantity, 'TRY'), Money::ofMinor($lineGross, 'TRY'));
        $order->addAddress(OrderAddressRole::Shipping, 'Efe Yılmaz', '05320000000', 'Atatürk Caddesi 1', null, 'Çukurova', 'Adana', '01170', 'TR');
        $order->addAddress(OrderAddressRole::Billing, 'Efe Yılmaz', '05320000000', 'Atatürk Caddesi 1', null, 'Çukurova', 'Adana', '01170', 'TR');
        $order->sealSnapshots();
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }
}
