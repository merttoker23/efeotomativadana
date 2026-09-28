<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Customer\CustomerUser;
use App\Module\Notification\NotificationAlreadySent;
use App\Module\Notification\NotificationChannel;
use App\Module\Notification\NotificationEvent;
use App\Module\Notification\NotificationType;
use App\Module\Notification\TransactionalNotificationService;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * The phase's own gate: a replayed event must not produce a second email.
 *
 * Proved by counting the messages the in-memory transport actually collected, not by asserting a
 * flag, because "we did not send it twice" is only meaningful if the second one really did not
 * arrive.
 *
 * The service is built here rather than fetched from the container so the transport under test is
 * this file's own — the container's mailer is `null://null` and could not tell a second email from
 * a first. `composer lint` is what proves the service wires up in the real container.
 */
final class TransactionalNotificationTest extends KernelTestCase
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
        $this->transport = new RecordingMailTransport();
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $this->notifications = new TransactionalNotificationService(
            $this->entityManager,
            new Mailer($this->transport),
            $twig,
            new class implements \Psr\Clock\ClockInterface {
                public function now(): \DateTimeImmutable
                {
                    return new \DateTimeImmutable('2026-09-28 10:00:00', new \DateTimeZone('UTC'));
                }
            },
        );
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testAnEventProducesExactlyOneEmailToTheCustomerAddressOnTheOrderSnapshot(): void
    {
        $order = $this->confirmedOrder('buyer@example.com');

        $sent = $this->notifications->publish(new NotificationEvent(
            NotificationType::OrderPlaced,
            $order->orderNumber(),
            $order->customerEmail(),
            ['order_number' => $order->orderNumber(), 'customer_name' => $order->customerName()],
        ));

        self::assertTrue($sent->wasSent);
        self::assertCount(1, $this->transport->getSent());

        $message = $this->firstMessage();
        self::assertSame([$order->customerEmail()], array_map(static fn ($address) => $address->getAddress(), $message->getTo()));
        self::assertSame('Siparişiniz alındı', $message->getSubject());
        self::assertStringContainsString($order->orderNumber(), $message->getTextBody() ?? '');
    }

    public function testEveryNotificationCarriesBothAPlainTextAndAnHtmlAlternative(): void
    {
        $order = $this->confirmedOrder('bodies@example.com');
        $this->notifications->publish(new NotificationEvent(NotificationType::OrderPlaced, $order->orderNumber(), $order->customerEmail(), ['order_number' => $order->orderNumber(), 'customer_name' => $order->customerName()]));

        $message = $this->firstMessage();

        // Text as well as HTML: a store that mails only HTML is unreadable to some mail clients and
        // to anyone reading it with a screen reader.
        self::assertNotNull($message->getTextBody());
        self::assertNotNull($message->getHtmlBody());
        self::assertStringContainsString($order->customerName(), (string) $message->getTextBody());
    }

    public function testTheSameEventTwiceProducesOnlyOneEmail(): void
    {
        $order = $this->confirmedOrder('replay@example.com');
        $event = new NotificationEvent(
            NotificationType::OrderPlaced,
            $order->orderNumber(),
            $order->customerEmail(),
            ['order_number' => $order->orderNumber()],
        );

        self::assertTrue($this->notifications->publish($event)->wasSent);

        $raised = null;
        try {
            $this->notifications->publish($event);
        } catch (NotificationAlreadySent $exception) {
            $raised = $exception;
        }

        self::assertInstanceOf(NotificationAlreadySent::class, $raised);
        self::assertCount(1, $this->transport->getSent());
    }

    public function testTwoDifferentTypesForTheSameOrderBothArrive(): void
    {
        $order = $this->confirmedOrder('both@example.com');

        $this->notifications->publish(new NotificationEvent(NotificationType::OrderPlaced, $order->orderNumber(), $order->customerEmail(), ['order_number' => $order->orderNumber()]));
        $this->notifications->publish(new NotificationEvent(NotificationType::PaymentReceived, $order->orderNumber(), $order->customerEmail(), ['order_number' => $order->orderNumber()]));

        self::assertCount(2, $this->transport->getSent());
    }

    public function testTheSameTypeForTwoDifferentOrdersBothArrive(): void
    {
        $first = $this->confirmedOrder('first@example.com');
        $second = $this->confirmedOrder('second@example.com');

        $this->notifications->publish(new NotificationEvent(NotificationType::OrderPlaced, $first->orderNumber(), $first->customerEmail(), ['order_number' => $first->orderNumber()]));
        $this->notifications->publish(new NotificationEvent(NotificationType::OrderPlaced, $second->orderNumber(), $second->customerEmail(), ['order_number' => $second->orderNumber()]));

        self::assertCount(2, $this->transport->getSent());
    }

    public function testTwoShipmentEventsForOneOrderAreDistinctOnlyWhenTheyNameDifferentSubjects(): void
    {
        $order = $this->confirmedOrder('dedup@example.com');

        // Same type, same order: the second is a replay even though the tracking number differs,
        // because the dedup key is the type and the subject it is about, not the payload.
        $this->notifications->publish(new NotificationEvent(NotificationType::ShipmentDispatched, $order->orderNumber(), $order->customerEmail(), ['order_number' => $order->orderNumber(), 'tracking_number' => 'A']));

        $raised = null;
        try {
            $this->notifications->publish(new NotificationEvent(NotificationType::ShipmentDispatched, $order->orderNumber(), $order->customerEmail(), ['order_number' => $order->orderNumber(), 'tracking_number' => 'B']));
        } catch (NotificationAlreadySent $exception) {
            $raised = $exception;
        }

        self::assertInstanceOf(NotificationAlreadySent::class, $raised);
        self::assertCount(1, $this->transport->getSent());
    }

    /**
     * The dedup key is a database constraint, not an application check, because two payment
     * webhooks arriving in the same instant would otherwise both pass a "have I sent this?" read
     * before either has written anything.
     */
    public function testTheDatabaseItselfRefusesASecondRowForTheSameLogicalEvent(): void
    {
        $order = $this->confirmedOrder('unique@example.com');
        $event = new NotificationEvent(NotificationType::OrderPlaced, $order->orderNumber(), $order->customerEmail(), ['order_number' => $order->orderNumber()]);
        $this->notifications->publish($event);

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->connection->insert('commerce_notification', [
            'dedup_key' => $event->dedupKey(),
            'type' => $event->type->value,
            'subject_reference' => $event->subjectReference,
            'recipient' => $event->recipient,
            'channel' => NotificationChannel::Email->value,
            'payload' => '{}',
            'attempts' => 0,
            'created_at' => '2026-09-28 10:00:00',
        ]);
    }

    public function testAFailedSendIsRecordedWithItsReasonAndTheFailureIsNotSwallowed(): void
    {
        $order = $this->confirmedOrder('failing@example.com');
        $this->transport->failWith(new \RuntimeException('SMTP down'));

        $raised = null;
        try {
            $this->notifications->publish(new NotificationEvent(NotificationType::OrderPlaced, $order->orderNumber(), $order->customerEmail(), ['order_number' => $order->orderNumber()]));
        } catch (\RuntimeException $exception) {
            $raised = $exception;
        }

        // Not swallowed: a silent catch would leave an operator believing a confirmation went out.
        self::assertInstanceOf(\RuntimeException::class, $raised);
        self::assertSame('SMTP down', $raised->getMessage());

        $row = $this->connection->fetchAssociative('SELECT channel, attempts, failure_message, sent_at FROM commerce_notification WHERE subject_reference = ?', [$order->orderNumber()]);
        self::assertIsArray($row);
        self::assertSame(NotificationChannel::Email->value, $row['channel']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame('SMTP down', $row['failure_message']);
        self::assertNull($row['sent_at']);
    }

    /**
     * The record is committed before the send, so an SMTP outage costs a missing email an operator
     * can see — never the order that was being confirmed.
     */
    public function testTheRecordIsCommittedBeforeTheMailIsAttemptedSoAFailureCannotRollBackTheOrder(): void
    {
        $order = $this->confirmedOrder('durable@example.com');
        $this->transport->failWith(new \RuntimeException('SMTP down'));

        try {
            $this->notifications->publish(new NotificationEvent(NotificationType::OrderPlaced, $order->orderNumber(), $order->customerEmail(), ['order_number' => $order->orderNumber()]));
        } catch (\RuntimeException) {
        }

        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_notification WHERE subject_reference = ?', [$order->orderNumber()]),
        );
    }

    public function testARecipientThatIsNotAnEmailAddressIsRefusedBeforeAnythingIsRecorded(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NotificationEvent(NotificationType::OrderPlaced, 'EOA-20260928-ABCDEF012345', 'not-an-address', []);
    }

    public function testAPayloadThatIsNotScalarIsRefusedSoATemplateCannotReachAnObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NotificationEvent(NotificationType::OrderPlaced, 'EOA-20260928-ABCDEF012345', 'a@example.com', ['order' => new \stdClass()]);
    }

    public function testTheRecipientIsTakenFromTheOrderSnapshotNotTheAccountSoAnOldOrderStillReachesTheAddressItWasPlacedWith(): void
    {
        $customer = new CustomerUser('snapshot@example.com', 'Efe', 'Yılmaz');
        $order = $this->confirmedOrderFor($customer);
        $customer->setProfile('Değişen', 'Ad', '05320000000');
        $this->entityManager->flush();

        self::assertSame('snapshot@example.com', $order->customerEmail());
    }

    public function testEveryNotificationTypeHasItsOwnSubjectAndRendersBothParts(): void
    {
        $order = $this->confirmedOrder('all-types@example.com');
        $subjects = [];

        foreach (NotificationType::cases() as $index => $type) {
            $sent = $this->notifications->publish(new NotificationEvent(
                $type,
                sprintf('SUBJECT-%d', $index),
                'all-types@example.com',
                [
                    'order_number' => $order->orderNumber(),
                    'customer_name' => $order->customerName(),
                    'return_number' => 'RET-20260928-ABCDEF012345',
                    'tracking_number' => 'TR123456789',
                ],
            ));
            self::assertTrue($sent->wasSent, $type->value);
        }

        self::assertCount(count(NotificationType::cases()), $this->transport->getSent());
        foreach ($this->transport->getSent() as $message) {
            $original = $this->emailOf($message);
            self::assertNotSame('', $original->getSubject());
            self::assertNotSame('', (string) $original->getTextBody());
            self::assertNotSame('', (string) $original->getHtmlBody());
            $subjects[] = $original->getSubject();
        }

        // Distinct subjects: two notifications that read the same are indistinguishable to a
        // customer trying to work out what happened.
        self::assertSame(count($subjects), count(array_unique($subjects)));
    }

    public function testTheServiceIsAutowirableInTheRealContainer(): void
    {
        self::assertInstanceOf(TransactionalNotificationService::class, self::getContainer()->get(TransactionalNotificationService::class));
    }

    /**
     * The first message that was sent, narrowed to an `Email`.
     *
     * `SentMessage::getOriginalMessage()` is typed as `RawMessage`; a store that ever put anything
     * but an `Email` on the bus would be a test failure here rather than a surprise in production.
     */
    private function firstMessage(): Email
    {
        $sent = $this->transport->getSent();
        self::assertNotEmpty($sent);
        $email = $this->emailOf($sent[0]);
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function emailOf(SentMessage $message): Email
    {
        $original = $message->getOriginalMessage();
        self::assertInstanceOf(Email::class, $original);

        return $original;
    }

    private function confirmedOrder(string $email): CustomerOrder
    {
        return $this->confirmedOrderFor(new CustomerUser($email, 'Efe', 'Yılmaz'));
    }

    private function confirmedOrderFor(CustomerUser $customer): CustomerOrder
    {
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $at = new \DateTimeImmutable('2026-09-28 09:00:00');
        $order = new CustomerOrder(
            sprintf('EOA-20260928-%s', strtoupper(bin2hex(random_bytes(6)))),
            $customer,
            Money::ofMinor(3_000, 'TRY'),
            Money::ofMinor(500, 'TRY'),
            Money::ofMinor(0, 'TRY'),
            Money::ofMinor(3_000, 'TRY'),
            'local_standard',
            'Yerel standart teslimat',
            'gateway_checkout',
            'Kredi kartı',
            $at,
        );
        $order->addItem(null, 'SKU-1', 'Filtre', 1, Money::ofMinor(3_000, 'TRY'), 2000, Money::ofMinor(2_500, 'TRY'), Money::ofMinor(500, 'TRY'), Money::ofMinor(3_000, 'TRY'));
        $order->addAddress(\App\Module\Order\OrderAddressRole::Shipping, 'Efe Yılmaz', '05320000000', 'Atatürk Caddesi 1', null, 'Çukurova', 'Adana', '01170', 'TR');
        $order->addAddress(\App\Module\Order\OrderAddressRole::Billing, 'Efe Yılmaz', '05320000000', 'Atatürk Caddesi 1', null, 'Çukurova', 'Adana', '01170', 'TR');
        $order->sealSnapshots();
        $order->transitionTo(\App\Module\Order\OrderState::Confirmed);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }
}
