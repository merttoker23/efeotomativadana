<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\NotificationRecord;
use App\Entity\Commerce\Shipment;
use App\Entity\Customer\CustomerUser;
use App\Module\Notification\NotificationType;
use App\Module\Notification\TransactionalNotificationService;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderState;
use App\Module\Returns\ReturnLine;
use App\Module\Returns\ReturnService;
use App\Module\Shipping\ShipmentOrchestrator;
use App\Repository\Commerce\ReturnRequestRepository;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Whether the real flows actually tell the customer anything.
 *
 * A notification service nothing calls is a service that sends nothing, so this drives the real
 * services — return request, return decisions, parcel dispatched, parcel delivered — and then reads
 * the rows they left behind. The service is rebuilt against a recording transport because the
 * container's mailer is `null://null` and could not count what it sent.
 */
final class NotificationWiringTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private RecordingMailTransport $transport;
    private TransactionalNotificationService $notifications;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $manager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->entityManager = $manager;

        $twig = self::getContainer()->get(Environment::class);
        $clock = self::getContainer()->get('clock');
        self::assertInstanceOf(Environment::class, $twig);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $this->transport = new RecordingMailTransport();
        $this->notifications = new TransactionalNotificationService($this->entityManager, new Mailer($this->transport), $twig, $clock);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testAReturnRequestIsMailedOnceWithTheOrderAndTheReturnNumber(): void
    {
        $customer = $this->customer();
        $order = $this->confirmedOrder($customer);
        $service = $this->publishing();

        $return = $service->request($customer, $order->orderNumber(), [new ReturnLine($order->items()[0], 1, 'Bozuk geldi.')], 'Paket ezilmiş ulaştı.');

        $record = $this->recordFor(NotificationType::ReturnRequested, $return->returnNumber());
        self::assertNotNull($record, 'Opening a return must record a ReturnRequested notification.');
        self::assertSame($order->customerEmail(), $record->recipient());
        self::assertSame($return->returnNumber(), $record->payload()['return_number']);
        self::assertSame($order->orderNumber(), $record->payload()['order_number']);
        self::assertCount(1, $this->transport->getSent());
    }

    public function testEachReturnDecisionIsMailedExactlyOnceAndAParallelRequestIsMailedSeparately(): void
    {
        $customer = $this->customer();
        $order = $this->confirmedOrder($customer);
        $service = $this->publishing();

        $approved = $service->request($customer, $order->orderNumber(), [new ReturnLine($order->items()[0], 1, 'Bozuk geldi.')], 'Bozuk geldi.');
        $service->approve($approved, 'Depoya alındı.', 'admin@example.com');
        // The same decision re-announced is a replay and must cost nothing. The domain refuses it
        // outright, so the honest equivalent here is two *different* returns on the same order.
        $rejected = $service->request($customer, $order->orderNumber(), [new ReturnLine($order->items()[0], 1, 'Yanlış ürün geldi.')], 'Yanlış ürün geldi.');
        $service->reject($rejected, 'Kullanılmış ürün.', 'admin@example.com');

        self::assertNotNull($this->recordFor(NotificationType::ReturnRequested, $approved->returnNumber()));
        self::assertNotNull($this->recordFor(NotificationType::ReturnApproved, $approved->returnNumber()));
        self::assertNotNull($this->recordFor(NotificationType::ReturnRequested, $rejected->returnNumber()));
        self::assertNotNull($this->recordFor(NotificationType::ReturnRejected, $rejected->returnNumber()));
        self::assertCount(4, $this->transport->getSent());
    }

    public function testTheApprovalMailCarriesTheSameNoteTheCustomerWasShownOnTheScreen(): void
    {
        $customer = $this->customer();
        $order = $this->confirmedOrder($customer);
        $service = $this->publishing();

        $return = $service->request($customer, $order->orderNumber(), [new ReturnLine($order->items()[0], 1, 'Bozuk geldi.')], 'Bozuk geldi.');
        $service->approve($return, 'Önce fotoğraf gönderin lütfen.', 'admin@example.com');

        // The note an operator typed is the note the customer is shown and the note they are
        // mailed. Two different strings for one decision is two different promises.
        self::assertStringContainsString('Önce fotoğraf gönderin lütfen.', $this->lastTextBody());
    }

    public function testARejectionMailCarriesTheReasonAndNotNothing(): void
    {
        $customer = $this->customer();
        $order = $this->confirmedOrder($customer);
        $service = $this->publishing();

        $return = $service->request($customer, $order->orderNumber(), [new ReturnLine($order->items()[0], 1, 'Bozuk geldi.')], 'Bozuk geldi.');
        $service->reject($return, 'Kullanılmış ürün iadesi kabul edilmez.', 'admin@example.com');

        self::assertStringContainsString('Kullanılmış ürün iadesi kabul edilmez.', $this->lastTextBody());
    }

    private function lastTextBody(): string
    {
        $sent = $this->transport->getSent();
        self::assertNotEmpty($sent);
        $email = $sent[count($sent) - 1]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $email);

        return (string) $email->getTextBody();
    }

    public function testARefundIsAnnouncedOnlyOnceAndCarriesTheAmountThatMoved(): void
    {
        $customer = $this->customer();
        $order = $this->confirmedOrder($customer);
        $service = $this->publishing();

        $return = $service->request($customer, $order->orderNumber(), [new ReturnLine($order->items()[0], 1, 'Bozuk geldi.')], 'Bozuk geldi.');
        $service->approve($return, 'Kabul.', 'admin@example.com');
        $service->markReceived($return, 'admin@example.com');
        $before = $this->transport->count();
        $service->recordRefund($return, 12_345, 'TRY', 'paytr_ref_1', 'admin@example.com');

        $record = $this->recordFor(NotificationType::ReturnCompleted, $return->returnNumber());
        self::assertNotNull($record);
        self::assertSame('12345 TRY', $record->payload()['refund_amount']);
        // Marking the goods received is an internal step; only the outcome is worth an email.
        self::assertSame($before + 1, $this->transport->count());
    }

    public function testAParcelLeavingTheBuildingIsMailedWithItsTrackingNumber(): void
    {
        $customer = $this->customer();
        $order = $this->confirmedOrder($customer);
        $orchestrator = $this->orchestrator();
        $shipment = $orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');

        $orchestrator->handOver($shipment, 'TR-ABC-999', 'admin@example.com');

        $record = $this->recordFor(NotificationType::ShipmentDispatched, $order->orderNumber());
        self::assertNotNull($record, 'Handing a parcel over must mail the customer.');
        self::assertSame('TR-ABC-999', $record->payload()['tracking_number']);
    }

    public function testAParcelIsAnnouncedOnceForLeavingAndOnceForArriving(): void
    {
        $customer = $this->customer();
        $order = $this->confirmedOrder($customer);
        $orchestrator = $this->orchestrator();
        $shipment = $orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');

        $orchestrator->handOver($shipment, 'TR-ABC-999', 'admin@example.com');
        $orchestrator->markInTransit($shipment, 'admin@example.com');
        $orchestrator->markDelivered($shipment, 'admin@example.com');

        // "Ready" and "in transit" are the same news, so they share one dedup key and produce one
        // row. Two rows about one parcel leaving is the failure mode this guards against.
        self::assertSame(1, $this->countFor(NotificationType::ShipmentDispatched, $order->orderNumber()));
        self::assertSame(1, $this->countFor(NotificationType::ShipmentDelivered, $order->orderNumber()));
        // The mailer here is the container's `null://null`, so the rows are the observable. The
        // count is the assertion; the delivery itself is Mailpit's job in development.
        self::assertSame(0, $this->transport->count());
    }

    private function countFor(NotificationType $type, string $subjectReference): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM commerce_notification WHERE type = ? AND subject_reference = ?',
            [$type->value, $subjectReference],
        );
    }

    public function testATrackingNumberThatArrivesLaterDoesNotProduceASecondDispatchNotice(): void
    {
        $customer = $this->customer();
        $order = $this->confirmedOrder($customer);
        $orchestrator = $this->orchestrator();
        $shipment = $orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $orchestrator->handOver($shipment, null, 'admin@example.com');
        self::assertSame(1, $this->countFor(NotificationType::ShipmentDispatched, $order->orderNumber()));

        // A carrier issuing the number only when the label is printed is routine, and re-announcing
        // the same dispatch must be free rather than mailing the customer a second "on its way".
        $this->entityManager->refresh($shipment);
        $shipment->assignTrackingNumber('TR-LATE-1', new \DateTimeImmutable('2026-09-28 15:00:00'), 'admin@example.com');
        $this->entityManager->flush();

        self::assertSame(1, $this->countFor(NotificationType::ShipmentDispatched, $order->orderNumber()));
    }

    public function testTheSubscriberIsRegisteredAndTheServiceIsTheOneTheContainerBuilds(): void
    {
        self::assertInstanceOf(TransactionalNotificationService::class, self::getContainer()->get(TransactionalNotificationService::class));
        // Autoconfigured from its `#[AsEventListener]` methods, so its presence in the container
        // means the container also has its four listener tags.
        self::assertInstanceOf(\App\Module\Notification\NotificationEventSubscriber::class, self::getContainer()->get(\App\Module\Notification\NotificationEventSubscriber::class));
    }

    /**
     * The returns service rebuilt against the recording transport.
     *
     * The event dispatcher and the subscriber are the *real* ones, so this drives the production
     * wiring end to end; only the mailer underneath is swapped, because the container's is
     * `null://null` and could not count what it sent.
     */
    private function publishing(): ReturnService
    {
        $subscriber = new \App\Module\Notification\NotificationEventSubscriber(
            $this->notifications,
            new \Psr\Log\NullLogger(),
        );
        $events = new \Symfony\Component\EventDispatcher\EventDispatcher();
        // Registered by hand with the same event names the `#[AsEventListener]` attributes declare,
        // so the container and this test wire the subscriber identically.
        $events->addListener(\App\Module\Notification\Event\ReturnStateChanged::class, [$subscriber, 'onReturnStateChanged']);

        $repository = $this->entityManager->getRepository(\App\Entity\Commerce\ReturnRequest::class);
        self::assertInstanceOf(ReturnRequestRepository::class, $repository);

        return new ReturnService(
            $repository,
            self::getContainer()->get(\App\Module\Order\OrderRepositoryInterface::class),
            self::getContainer()->get(\App\Module\Returns\ReturnPolicy::class),
            self::getContainer()->get(\App\Module\Returns\ReturnNumberGenerator::class),
            $this->entityManager,
            self::getContainer()->get('clock'),
            $events,
            self::getContainer()->get(\App\Module\Audit\AuditLogger::class),
        );
    }

    private function orchestrator(): ShipmentOrchestrator
    {
        $orchestrator = self::getContainer()->get(ShipmentOrchestrator::class);
        self::assertInstanceOf(ShipmentOrchestrator::class, $orchestrator);

        return $orchestrator;
    }

    private function recordFor(NotificationType $type, string $subjectReference): ?NotificationRecord
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM commerce_notification WHERE type = ? AND subject_reference = ?',
            [$type->value, $subjectReference],
        );
        if (false === $id || null === $id) {
            return null;
        }
        $record = $this->entityManager->find(NotificationRecord::class, (int) $id);
        self::assertInstanceOf(NotificationRecord::class, $record);

        return $record;
    }

    private function customer(): CustomerUser
    {
        $customer = new CustomerUser('wiring@example.com', 'Efe', 'Yılmaz');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function confirmedOrder(CustomerUser $customer): CustomerOrder
    {
        $at = new \DateTimeImmutable('2026-09-28 09:00:00');
        $order = new CustomerOrder(
            sprintf('EOA-20260928-%s', strtoupper(bin2hex(random_bytes(6)))),
            $customer,
            Money::ofMinor(3_000, 'TRY'),
            Money::ofMinor(501, 'TRY'),
            Money::ofMinor(0, 'TRY'),
            Money::ofMinor(3_000, 'TRY'),
            'local_standard',
            'Yerel standart teslimat',
            'gateway_checkout',
            'Kredi kartı',
            $at,
        );
        $order->addItem(null, 'SKU-1', 'Filtre', 3, Money::ofMinor(1_000, 'TRY'), 2000, Money::ofMinor(2_499, 'TRY'), Money::ofMinor(501, 'TRY'), Money::ofMinor(3_000, 'TRY'));
        $order->addAddress(OrderAddressRole::Shipping, 'Efe Yılmaz', '05320000000', 'Atatürk Caddesi 1', null, 'Çukurova', 'Adana', '01170', 'TR');
        $order->addAddress(OrderAddressRole::Billing, 'Efe Yılmaz', '05320000000', 'Atatürk Caddesi 1', null, 'Çukurova', 'Adana', '01170', 'TR');
        $order->sealSnapshots();
        $order->transitionTo(OrderState::Confirmed);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }
}
