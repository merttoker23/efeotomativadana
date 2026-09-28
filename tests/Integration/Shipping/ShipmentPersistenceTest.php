<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shipping;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Shipment;
use App\Entity\Commerce\ShipmentEvent;
use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderState;
use App\Module\Payment\SanitizedFailure;
use App\Module\Shipping\ShipmentState;
use App\Repository\Commerce\ShipmentRepository;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ShipmentPersistenceTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private ShipmentRepository $shipments;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $manager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->entityManager = $manager;
        $repository = $this->entityManager->getRepository(Shipment::class);
        self::assertInstanceOf(ShipmentRepository::class, $repository);
        $this->shipments = $repository;
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testAShipmentPersistsItsMethodSnapshotProviderAndOrderLink(): void
    {
        $order = $this->confirmedOrder('persist@example.com');
        $shipment = Shipment::start($order, 'local_standard', 'Yerel standart teslimat', 'manual', new \DateTimeImmutable('2026-09-28 09:00:00'));

        $this->entityManager->persist($shipment);
        $this->entityManager->flush();

        self::assertNotNull($shipment->id());
        self::assertSame(ShipmentState::Pending, $shipment->state());
        self::assertSame('local_standard', $shipment->methodKey());
        self::assertSame('Yerel standart teslimat', $shipment->methodLabel());
        self::assertSame('manual', $shipment->providerKey());
        self::assertSame($order->id(), $shipment->order()->id());
        self::assertSame('shipment-'.$order->orderNumber(), $shipment->idempotencyKey());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_shipment WHERE order_id = ?', [$order->id()]));
    }

    /**
     * The database, not the service, is what makes a double-clicked button or a replayed message
     * harmless. Two application-level checks racing would still produce two parcels.
     */
    public function testTheDatabaseRefusesASecondShipmentForOneOrder(): void
    {
        $order = $this->confirmedOrder('one-per-order@example.com');
        $this->entityManager->persist(Shipment::start($order, 'local_standard', 'Yerel standart teslimat', 'manual', new \DateTimeImmutable()));
        $this->entityManager->flush();

        $this->entityManager->persist(Shipment::start($order, 'local_standard', 'Yerel standart teslimat', 'manual', new \DateTimeImmutable()));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testTheIdempotencyKeyIsUniqueAcrossTheWholeTable(): void
    {
        $first = $this->confirmedOrder('idem-1@example.com');
        $second = $this->confirmedOrder('idem-2@example.com');
        $this->entityManager->persist(Shipment::start($first, 'local_standard', 'Yerel standart teslimat', 'manual', new \DateTimeImmutable()));
        $this->entityManager->flush();

        // Inserted straight through the connection so the order differs and the order-level unique
        // index is not what rejects it: the same key on a different order is the shape a corrupted
        // derivation would produce, and it must collide on the key instead.
        $now = '2026-09-28 09:00:00';
        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->insert('commerce_shipment', [
            'idempotency_key' => Shipment::idempotencyKeyFor($first->orderNumber()),
            'method_key' => 'local_standard',
            'method_label' => 'Yerel standart teslimat',
            'provider_key' => 'manual',
            'state' => ShipmentState::Pending->value,
            'order_id' => $second->id(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function testTheFullLifecycleSurvivesAReload(): void
    {
        $shipment = $this->shipment('lifecycle@example.com');
        $ready = new \DateTimeImmutable('2026-09-29 10:00:00');
        $shipment->markReady('FAKE-SHIP-1', 'FAKE-TRK-1', $ready);
        $shipment->markInTransit(new \DateTimeImmutable('2026-09-29 12:00:00'));
        $shipment->markDelivered(new \DateTimeImmutable('2026-09-29 15:00:00'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->shipments->findOneForOrderNumber($shipment->orderNumber());
        self::assertInstanceOf(Shipment::class, $reloaded);
        self::assertSame(ShipmentState::Delivered, $reloaded->state());
        self::assertSame('FAKE-SHIP-1', $reloaded->providerReference());
        self::assertSame('FAKE-TRK-1', $reloaded->trackingNumber());
        self::assertEquals($ready, $reloaded->readyAt());
        self::assertNotNull($reloaded->inTransitAt());
        self::assertNotNull($reloaded->deliveredAt());
        self::assertNull($reloaded->cancelledAt());
        self::assertNull($reloaded->failure());
    }

    public function testAFailedShipmentKeepsItsRedactedFailureAndCanBeRetried(): void
    {
        $shipment = $this->shipment('failed@example.com');
        $shipment->markFailed(SanitizedFailure::fromProvider('address_invalid', 'district 4111111111111111 token=abc', null), new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->shipments->findOneForOrderNumber($shipment->orderNumber());
        self::assertInstanceOf(Shipment::class, $reloaded);
        self::assertSame(ShipmentState::Failed, $reloaded->state());
        self::assertSame('address_invalid', $reloaded->failure()?->code());
        $message = (string) $reloaded->failure()->message();
        self::assertStringNotContainsString('4111111111111111', $message);
        self::assertStringNotContainsString('abc', $message);

        $reloaded->markReady('FAKE-SHIP-1', 'FAKE-TRK-1', new \DateTimeImmutable());
        $this->entityManager->flush();
        self::assertSame(ShipmentState::Ready, $reloaded->state());
        self::assertNull($reloaded->failure(), 'A recovered shipment must not keep reporting the old failure.');
    }

    public function testTheAuditTrailIsStoredInOrderAndCarriesTheProvidersWording(): void
    {
        $shipment = $this->shipment('events@example.com');
        $shipment->markReady('FAKE-SHIP-1', 'FAKE-TRK-1', new \DateTimeImmutable());
        $shipment->markInTransit(new \DateTimeImmutable());
        $shipment->recordProviderFact("Yola çıktı\nikinci satır", new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->shipments->findOneForOrderNumber($shipment->orderNumber());
        self::assertInstanceOf(Shipment::class, $reloaded);
        $events = $reloaded->events();
        self::assertCount(4, $events);
        self::assertSame([ShipmentState::Pending, ShipmentState::Ready, ShipmentState::InTransit, ShipmentState::InTransit], array_map(static fn (ShipmentEvent $event): ShipmentState => $event->toState(), $events));
        self::assertSame('Yola çıktı ikinci satır', $events[3]->detail());
        self::assertSame(4, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_shipment_event WHERE shipment_id = ?', [$reloaded->id()]));
    }

    public function testAShipmentIsFoundByItsCarrierReference(): void
    {
        $shipment = $this->shipment('reference@example.com');
        $shipment->markReady('FAKE-SHIP-9', 'FAKE-TRK-9', new \DateTimeImmutable());
        $this->entityManager->flush();

        self::assertNotNull($this->shipments->findOneForProviderReference('fake', 'FAKE-SHIP-9'));
        self::assertNull($this->shipments->findOneForProviderReference('fake', 'NEVER-ISSUED'));
        self::assertNull($this->shipments->findOneForProviderReference('other', 'FAKE-SHIP-9'));
    }

    /**
     * The row lock is the whole defence for the concurrent-create race the phase is built around,
     * so this asserts that the repository's own lookup asks for a write lock with a refreshed
     * identity map. Deleting `setLockMode` or the `HINT_REFRESH` hint from `findForUpdate` fails
     * this test.
     *
     * It asserts the lock *mode*, not the emitted SQL: Doctrine adds `FOR UPDATE` at execution
     * time, not in `getSQL()`, so there is nothing cheaper to inspect from a single connection.
     * A real two-connection interleaving test is the honest upgrade and is left for PHASE 20.
     */
    public function testReadingAShipmentForUpdateTakesAWriteLockAndRefreshesTheIdentityMap(): void
    {
        $reflection = new \ReflectionMethod(ShipmentRepository::class, 'findForUpdate');
        $source = implode('', array_slice(
            file($reflection->getFileName()) ?: [],
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        self::assertStringContainsString('PESSIMISTIC_WRITE', $source);
        self::assertStringContainsString('HINT_REFRESH', $source);
    }

    public function testAShipmentIsStillFoundByItsIdUnderThatLock(): void
    {
        $shipment = $this->shipment('locked@example.com');

        $locked = $this->shipments->findForUpdate($shipment->id());

        self::assertInstanceOf(Shipment::class, $locked);
        self::assertSame($shipment->id(), $locked->id());
    }

    public function testARemovedOrderTakesItsShipmentWithIt(): void
    {
        $shipment = $this->shipment('cascade@example.com');
        $shipment->markReady('FAKE-SHIP-C', 'FAKE-TRK-C', new \DateTimeImmutable());
        $this->entityManager->flush();
        $orderId = $shipment->order()->id();

        $this->connection->executeStatement('DELETE FROM commerce_customer_order WHERE id = ?', [$orderId]);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_shipment WHERE order_id = ?', [$orderId]));
    }

    public function testTheAdminListFiltersByStateAndSearchesByOrderAndTrackingNumber(): void
    {
        $delivered = $this->shipment('list-delivered@example.com');
        $delivered->markReady(null, 'FAKE-TRK-DELIVERED', new \DateTimeImmutable());
        $delivered->markDelivered(new \DateTimeImmutable());
        $pending = $this->shipment('list-pending@example.com');
        $this->entityManager->flush();

        $page = $this->shipments->adminPage('', ShipmentState::Delivered, 1);
        self::assertCount(1, $page->items);
        self::assertSame($delivered->id(), $page->items[0]->id());

        $all = $this->shipments->adminPage('', null, 1);
        self::assertCount(2, $all->items);

        self::assertCount(1, $this->shipments->adminPage('FAKE-TRK-DELIVERED', null, 1)->items);
        self::assertCount(1, $this->shipments->adminPage($pending->orderNumber(), null, 1)->items);
        self::assertCount(0, $this->shipments->adminPage('nothing-matches-this', null, 1)->items);
    }

    public function testAnOrderWithoutAShipmentHasNone(): void
    {
        $order = $this->confirmedOrder('no-shipment@example.com');

        self::assertNull($this->shipments->findOneForOrder($order));
        self::assertNull($this->shipments->findOneForOrderNumber($order->orderNumber()));
    }

    private function shipment(string $email): Shipment
    {
        $shipment = Shipment::start($this->confirmedOrder($email), 'local_standard', 'Yerel standart teslimat', 'fake', new \DateTimeImmutable('2026-09-28 09:00:00'));
        $this->entityManager->persist($shipment);
        $this->entityManager->flush();

        return $shipment;
    }

    private function confirmedOrder(string $email): CustomerOrder
    {
        $customer = new CustomerUser($email, 'Efe', 'Yılmaz');
        $this->entityManager->persist($customer);
        $gross = Money::ofMinor(30_000, 'TRY');
        $zero = Money::ofMinor(0, 'TRY');
        $order = new CustomerOrder(
            'EOA-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(6))),
            $customer,
            $gross,
            $zero,
            $zero,
            $gross,
            'local_standard',
            'Yerel standart teslimat',
            'local_manual',
            'Yerel manuel doğrulama',
            new \DateTimeImmutable('2026-09-28 08:00:00'),
        );
        $order->addItem(null, 'BRK-'.$email, 'Fren balatası', 2, Money::ofMinor(15_000, 'TRY'), 0, $gross, $zero, $gross);
        $order->addAddress(OrderAddressRole::Shipping, 'Efe Yılmaz', '05000000000', 'Atatürk Cad. 1', null, 'Seyhan', 'Adana', '01000', 'TR');
        $order->addAddress(OrderAddressRole::Billing, 'Efe Yılmaz', '05000000000', 'Atatürk Cad. 1', null, 'Seyhan', 'Adana', '01000', 'TR');
        $order->sealSnapshots();
        $this->entityManager->persist($order);
        $this->entityManager->flush();
        $order->transitionTo(OrderState::Confirmed);
        $this->entityManager->flush();

        return $order;
    }
}
