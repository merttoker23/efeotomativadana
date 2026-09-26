<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Payment;
use App\Entity\Commerce\PaymentAttempt;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Entity\Customer\CustomerUser;
use App\Module\Checkout\GatewayCheckoutPaymentOption;
use App\Module\Order\OrderAddressRole;
use App\Module\Payment\PaymentState;
use App\Module\Payment\SanitizedFailure;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PaymentPersistenceTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $manager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->entityManager = $manager;
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testPaymentPersistsAmountCurrencyProviderAndOrderLink(): void
    {
        $order = $this->order('persist@example.com', 45_000);
        $payment = Payment::start($order, 'fake', Money::ofMinor(45_000, 'TRY'), new \DateTimeImmutable('2026-09-25 10:00:00 UTC'));

        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        self::assertNotNull($payment->id());
        self::assertSame(PaymentState::Pending, $payment->state());
        self::assertSame(45_000, $payment->amount()->minorAmount());
        self::assertSame('TRY', $payment->amount()->currency());
        self::assertSame('fake', $payment->providerKey());
        self::assertSame($order->id(), $payment->order()->id());
        self::assertSame('gateway_checkout', $payment->methodKey());
        self::assertSame('Kredi kartı ile ödeme', $payment->methodLabel());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_payment WHERE order_id = ?', [$order->id()]));
    }

    public function testOnlyOnePaymentAggregateMayExistPerOrder(): void
    {
        $order = $this->order('single@example.com', 10_000);
        $amount = Money::ofMinor(10_000, 'TRY');
        $this->entityManager->persist(Payment::start($order, 'fake', $amount, new \DateTimeImmutable()));
        $this->entityManager->flush();
        $this->entityManager->persist(Payment::start($order, 'fake', $amount, new \DateTimeImmutable()));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testIdempotencyKeyIsUniqueAcrossAttempts(): void
    {
        $payment = $this->payment('idem@example.com', 20_000);
        $this->entityManager->persist($payment->beginAttempt('idem-key-1'));
        $this->entityManager->flush();

        $this->expectException(\DomainException::class);
        $payment->beginAttempt('idem-key-1');
    }

    public function testPersistenceRejectsAnIdempotencyKeyReusedByAnotherPayment(): void
    {
        $first = $this->payment('idem-a@example.com', 20_000, 'alpha');
        $second = $this->payment('idem-b@example.com', 30_000, 'beta');
        $this->entityManager->persist($first->beginAttempt('shared-key'));
        $this->entityManager->flush();
        $this->entityManager->persist($second->beginAttempt('shared-key'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testAttemptRejectsABlankIdempotencyKey(): void
    {
        $payment = $this->payment('blank@example.com', 20_000);

        $this->expectException(\InvalidArgumentException::class);
        $payment->beginAttempt('   ');
    }

    public function testAttemptSequenceIsMonotonicWithinOnePayment(): void
    {
        $payment = $this->payment('sequence@example.com', 20_000);

        self::assertSame(1, $payment->beginAttempt('key-1')->sequence());
        self::assertSame(2, $payment->beginAttempt('key-2')->sequence());
        self::assertSame(3, $payment->beginAttempt('key-3')->sequence());
    }

    public function testAttemptAmountMustMatchThePaymentAmount(): void
    {
        $payment = $this->payment('amount@example.com', 20_000);

        $this->expectException(\InvalidArgumentException::class);
        $payment->beginAttempt('key-1', Money::ofMinor(19_999, 'TRY'));
    }

    public function testARepeatedProviderReferenceIsAllowedBecauseIdempotentRetriesReuseIt(): void
    {
        $first = $this->payment('ref-first@example.com', 20_000, 'alpha');
        $second = $this->payment('ref-second@example.com', 20_000, 'alpha');
        $firstAttempt = $first->beginAttempt('a1');
        $secondAttempt = $second->beginAttempt('b1');
        $firstAttempt->assignProviderReference('REF-SHARED');
        $secondAttempt->assignProviderReference('REF-SHARED');
        $this->entityManager->persist($firstAttempt);
        $this->entityManager->persist($secondAttempt);
        $this->entityManager->flush();

        $third = $first->beginAttempt('a2');
        $third->assignProviderReference('REF-SHARED');
        $this->entityManager->persist($third);
        $this->entityManager->flush();

        self::assertCount(2, $first->attempts());
        $this->entityManager->clear();
        // Three rows share the reference: one per payment plus the repeat on the first payment.
        self::assertSame(3, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM commerce_payment_attempt WHERE provider_key = ? AND provider_reference = ?',
            ['alpha', 'REF-SHARED'],
        ));
    }

    public function testProviderReferenceIsQueryableSoAReplayedWebhookCanBeTraced(): void
    {
        $payment = $this->payment('ref-lookup@example.com', 20_000, 'alpha');
        $attempt = $payment->beginAttempt('a1');
        $attempt->assignProviderReference('REF-LOOKUP');
        $this->entityManager->flush();

        self::assertSame($attempt, $this->repository()->findAttemptByProviderReference('alpha', 'REF-LOOKUP'));
        self::assertNull($this->repository()->findAttemptByProviderReference('beta', 'REF-LOOKUP'));
    }

    public function testReturnTokenIsUniqueSoACallbackCannotAddressAnotherAttempt(): void
    {
        $first = $this->payment('token-first@example.com', 20_000, 'alpha');
        $second = $this->payment('token-second@example.com', 20_000, 'beta');
        $firstAttempt = $first->beginAttempt('a1');
        $secondAttempt = $second->beginAttempt('b1');
        $this->entityManager->persist($firstAttempt);
        $this->entityManager->persist($secondAttempt);
        $this->entityManager->flush();

        self::assertNotSame($firstAttempt->returnToken(), $secondAttempt->returnToken());
        self::assertSame(64, mb_strlen($firstAttempt->returnToken()));
    }

    public function testFailureMetadataIsPersistedSanitizedAndBounded(): void
    {
        $payment = $this->payment('failure@example.com', 20_000);
        $attempt = $payment->beginAttempt('key-1');
        $payment->markFailed($attempt, SanitizedFailure::fromProvider('card_declined', 'Declined 4111111111111111 cvc=123', null), new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->entityManager->clear();

        $persisted = $this->entityManager->find(PaymentAttempt::class, $attempt->id());
        self::assertInstanceOf(PaymentAttempt::class, $persisted);
        self::assertSame('card_declined', $persisted->failure()?->code());
        self::assertStringNotContainsString('4111111111111111', (string) $persisted->failure()->message());
        self::assertStringNotContainsString('123', (string) $persisted->failure()->message());
    }

    public function testRefundCannotExceedTheCapturedAmount(): void
    {
        $payment = $this->payment('refund@example.com', 30_000);
        $attempt = $payment->beginAttempt('key-1');
        $payment->markSucceeded($attempt, 'REF-1', Money::ofMinor(30_000, 'TRY'), new \DateTimeImmutable());
        $payment->recordRefund(Money::ofMinor(20_000, 'TRY'), 'REFUND-1', 'customer request', new \DateTimeImmutable());
        $this->entityManager->flush();

        self::assertSame(20_000, $payment->refundedAmount()->minorAmount());
        self::assertSame(PaymentState::PartiallyRefunded, $payment->state());
        self::assertSame(10_000, $payment->refundableAmount()->minorAmount());

        $this->expectException(\DomainException::class);
        $payment->recordRefund(Money::ofMinor(10_001, 'TRY'), 'REFUND-2', 'too much', new \DateTimeImmutable());
    }

    public function testFullRefundMovesThePaymentToRefunded(): void
    {
        $payment = $this->payment('full-refund@example.com', 30_000);
        $attempt = $payment->beginAttempt('key-1');
        $payment->markSucceeded($attempt, 'REF-1', Money::ofMinor(30_000, 'TRY'), new \DateTimeImmutable());
        $payment->recordRefund(Money::ofMinor(10_000, 'TRY'), 'REFUND-1', 'partial', new \DateTimeImmutable());
        $payment->recordRefund(Money::ofMinor(20_000, 'TRY'), 'REFUND-2', 'rest', new \DateTimeImmutable());

        self::assertSame(PaymentState::Refunded, $payment->state());
        self::assertSame(0, $payment->refundableAmount()->minorAmount());
        self::assertCount(2, $payment->refunds());
    }

    public function testEventTrailRecordsEveryStateChangeWithItsSource(): void
    {
        $payment = $this->payment('events@example.com', 30_000);
        $attempt = $payment->beginAttempt('key-1');
        $payment->markRequiresAction($attempt, new \DateTimeImmutable());
        $payment->markSucceeded($attempt, 'REF-1', Money::ofMinor(30_000, 'TRY'), new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Payment::class, $payment->id());
        self::assertInstanceOf(Payment::class, $reloaded);
        self::assertSame(
            [PaymentState::Pending->value, PaymentState::RequiresAction->value, PaymentState::Succeeded->value],
            array_map(static fn ($event): string => $event->toState()->value, $reloaded->events()),
        );
        self::assertSame('payment', $reloaded->events()[0]->source());
        self::assertSame([null, 1, 1], array_map(static fn ($event): ?int => $event->attemptSequence(), $reloaded->events()));
    }

    public function testCapturedAmountIsImmutableOnceSet(): void
    {
        $payment = $this->payment('captured@example.com', 30_000);
        $attempt = $payment->beginAttempt('key-1');
        $payment->markSucceeded($attempt, 'REF-1', Money::ofMinor(30_000, 'TRY'), new \DateTimeImmutable());

        $this->expectException(\DomainException::class);
        $payment->markSucceeded($attempt, 'REF-1', Money::ofMinor(30_000, 'TRY'), new \DateTimeImmutable());
    }

    public function testPaymentRequiresTheOrderGrandTotal(): void
    {
        $order = $this->order('mismatch@example.com', 45_000);

        $this->expectException(\InvalidArgumentException::class);
        Payment::start($order, 'fake', Money::ofMinor(44_999, 'TRY'), new \DateTimeImmutable());
    }

    public function testPaymentRequiresAGatewayBackedOrderPaymentMethod(): void
    {
        $order = $this->order('manual@example.com', 20_000, 'local_manual');

        $this->expectException(\InvalidArgumentException::class);
        Payment::start($order, 'fake', Money::ofMinor(20_000, 'TRY'), new \DateTimeImmutable());
    }

    public function testAttemptCannotSucceedTwice(): void
    {
        $payment = $this->payment('double-success@example.com', 30_000);
        $attempt = $payment->beginAttempt('key-1');
        $payment->markSucceeded($attempt, 'REF-1', Money::ofMinor(30_000, 'TRY'), new \DateTimeImmutable());

        $this->expectException(\DomainException::class);
        $payment->markSucceeded($attempt, 'REF-1', Money::ofMinor(30_000, 'TRY'), new \DateTimeImmutable());
    }

    public function testAttemptCannotFailAfterItSucceeded(): void
    {
        $payment = $this->payment('late-failure@example.com', 30_000);
        $attempt = $payment->beginAttempt('key-1');
        $payment->markSucceeded($attempt, 'REF-1', Money::ofMinor(30_000, 'TRY'), new \DateTimeImmutable());

        $this->expectException(\DomainException::class);
        $payment->markFailed($attempt, SanitizedFailure::fromProvider('timeout', 'late', null), new \DateTimeImmutable());
    }

    public function testAttemptAmountMismatchCannotMarkThePaymentSuccessful(): void
    {
        $payment = $this->payment('short-capture@example.com', 30_000);
        $attempt = $payment->beginAttempt('key-1');

        try {
            $payment->markSucceeded($attempt, 'REF-1', Money::ofMinor(29_999, 'TRY'), new \DateTimeImmutable());
            self::fail('A short capture must not mark the payment successful.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('does not match', $exception->getMessage());
        }

        self::assertSame(PaymentState::Pending, $payment->state());
        self::assertSame(0, $payment->capturedAmount()->minorAmount());
    }

    public function testEventTimestampsAreMonotonicAndNotStale(): void
    {
        $payment = $this->payment('event-time@example.com', 30_000);
        $attempt = $payment->beginAttempt('key-1');
        $payment->markRequiresAction($attempt, new \DateTimeImmutable('2026-09-25 10:00:00 UTC'));
        $payment->markSucceeded($attempt, 'REF-1', Money::ofMinor(30_000, 'TRY'), new \DateTimeImmutable('2026-09-25 10:05:00 UTC'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Payment::class, $payment->id());
        self::assertInstanceOf(Payment::class, $reloaded);
        $times = array_map(static fn ($event): string => $event->occurredAt()->format('H:i:s'), $reloaded->events());
        // An audit trail whose rows share one timestamp is not an audit trail: every event must
        // carry the time of the change it records, not the previous change's time.
        self::assertSame($times, array_values(array_unique($times)), 'events: '.implode(',', $times));
    }

    public function testGatewayCheckoutOptionKeyIsStable(): void
    {
        self::assertSame('gateway_checkout', (new GatewayCheckoutPaymentOption())->key());
    }

    private function repository(): \App\Repository\Commerce\PaymentRepository
    {
        $repository = self::getContainer()->get('doctrine')->getRepository(Payment::class);
        self::assertInstanceOf(\App\Repository\Commerce\PaymentRepository::class, $repository);

        return $repository;
    }

    private function payment(string $email, int $grandTotalMinor, string $provider = 'fake'): Payment
    {
        $order = $this->order($email, $grandTotalMinor);
        $payment = Payment::start($order, $provider, Money::ofMinor($grandTotalMinor, 'TRY'), new \DateTimeImmutable());
        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        return $payment;
    }

    private function order(string $email, int $grandTotalMinor, string $paymentOptionKey = 'gateway_checkout'): CustomerOrder
    {
        $customer = new CustomerUser($email, 'Efe', 'Yılmaz');
        $customer->setPassword('test-password-hash');
        $product = new Product('PAY-SKU-' . bin2hex(random_bytes(8)), 'Ödeme Ürünü', 'odeme-urunu-' . bin2hex(random_bytes(8)));
        $product->publish();
        $price = new ProductPrice($product, Money::ofMinor($grandTotalMinor, 'TRY'), \App\Module\Pricing\TaxCategory::of('replacement-part'), \App\Module\Pricing\TaxRate::fromBasisPoints(2_000));
        $inventory = new ProductInventory($product, 5);
        $zero = Money::ofMinor(0, 'TRY');
        $gross = Money::ofMinor($grandTotalMinor, 'TRY');
        $order = new CustomerOrder(
            'EOA-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(6))),
            $customer,
            $gross,
            $zero,
            $zero,
            $gross,
            'local_standard',
            'Yerel standart teslimat',
            $paymentOptionKey,
            'Kredi kartı ile ödeme',
            new \DateTimeImmutable(),
        );
        $order->addItem($product, $product->sku(), $product->name(), 1, $gross, 2_000, $gross, $zero, $gross);
        $order->addAddress(OrderAddressRole::Shipping, 'Efe Yılmaz', '05000000000', 'Cadde 1', null, 'Seyhan', 'Adana', null, 'TR');
        $order->addAddress(OrderAddressRole::Billing, 'Efe Yılmaz', '05000000000', 'Cadde 1', null, 'Seyhan', 'Adana', null, 'TR');
        $order->sealSnapshots();
        foreach ([$customer, $product, $price, $inventory, $order] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return $order;
    }
}
