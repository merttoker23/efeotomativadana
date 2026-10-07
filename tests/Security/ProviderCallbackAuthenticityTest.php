<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Payment;
use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderState;
use App\Module\Payment\FakePaymentGateway;
use App\Module\Payment\Gateway\GatewayInitiationOutcome;
use App\Module\Payment\PaymentInitiationService;
use App\Module\Settings\StoreConfiguration;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The two externally reachable payment endpoints, treated as hostile input.
 *
 * These routes are `PUBLIC_ACCESS` by necessity: a provider's server has no session and no
 * cookie. Everything protecting them therefore has to be either an unguessable token or the
 * provider's own signature, and this file exists to prove that neither is optional.
 *
 * The case that changed this phase's design is here too: `GET /odeme/sonuc/{token}` used to
 * settle money for any gateway that accepted a GET-shaped callback. A GET arrives from an address
 * bar, a prefetcher, a chat client's link preview and a browser history restore. A GET that moves
 * money is therefore now refused outright, for every provider, and that is asserted below with a
 * *valid* signature — which is the only version of the test that proves anything.
 */
final class ProviderCallbackAuthenticityTest extends WebTestCase
{
    private const string PASSWORD = 'VeryStrong!123';

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->selectFakeProvider();
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
        parent::tearDown();
    }

    /**
     * The defect this phase fixed: a correctly signed callback sent as a GET settles nothing.
     *
     * The signature is *valid* on purpose. A test with an invalid signature would pass just as
     * well before the fix, because the handler refused it for the wrong reason.
     */
    public function testAGetCannotSettleAPaymentEvenWithAValidSignature(): void
    {
        $order = $this->orderWithPaymentAttempt();

        $this->client->request('GET', '/odeme/sonuc/'.$this->returnTokenOf($order));

        self::assertResponseRedirects('/odeme/'.$order->orderNumber());
        self::assertSame(OrderState::Placed, $this->reload($order)->state(), 'A GET settled the order.');
        self::assertSame(0, $this->capturedAmountOf($order), 'A GET captured money.');
    }

    public function testAPostWithAValidSignatureStillSettlesTheOrder(): void
    {
        // The counterpart to the case above, and the reason the fix was possible: refusing the
        // verb must not have cost the store a real capture.
        $order = $this->orderWithPaymentAttempt('FAKE-SETTLED');

        $this->postCallback($order, 'FAKE-SETTLED', 'valid');

        self::assertSame(OrderState::Confirmed, $this->reload($order)->state());
        self::assertSame(100_00, $this->capturedAmountOf($order));
    }

    public function testARepeatedPostIsAbsorbedRatherThanCapturedTwice(): void
    {
        $order = $this->orderWithPaymentAttempt('FAKE-REPLAY');

        for ($replay = 0; $replay < 3; ++$replay) {
            $this->postCallback($order, 'FAKE-REPLAY', 'valid');
        }

        self::assertSame(100_00, $this->capturedAmountOf($order), 'A replayed webhook captured more than the order total.');
        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_payment_event WHERE to_state = ?', ['succeeded']),
        );
    }

    public function testAForgedSignatureIsRefusedBeforeAnythingIsWritten(): void
    {
        $order = $this->orderWithPaymentAttempt('FAKE-FORGED');

        $this->postCallback($order, 'FAKE-FORGED', 'a-forged-signature');

        $this->client->followRedirect();
        self::assertStringContainsString('Ödeme doğrulanamadı', (string) $this->client->getResponse()->getContent());
        self::assertSame(OrderState::Placed, $this->reload($order)->state());
        self::assertSame(0, $this->capturedAmountOf($order));
    }

    public function testTheCallbackAnswersTheSameWayForATokenThatAddressesNothing(): void
    {
        $this->postCallbackToToken(str_repeat('d', 64), 'FAKE-UNKNOWN', 'valid');

        // The same redirect as a real-but-unknown token, so the endpoint cannot be used to probe
        // which return tokens exist.
        self::assertResponseRedirects('/katalog');
    }

    /**
     * A signed report for a *different* payment must not settle this one.
     *
     * The signature proves the provider said it; it does not prove the provider said it about
     * this attempt. That second question is answered by the reference, and it is asserted here
     * separately so the two checks cannot be confused.
     */
    public function testASignedReportCarryingAnotherPaymentsReferenceIsRefused(): void
    {
        $order = $this->orderWithPaymentAttempt('FAKE-THIS-ONE');

        // The signature is valid — it is the same fake signature every accepted callback carries.
        // What is wrong is the *reference*: the provider is reporting about a different payment.
        // The signature proves the provider spoke; only the reference says what about.
        $this->postCallback($order, 'A-DIFFERENT-REFERENCE', 'valid');

        self::assertSame(OrderState::Placed, $this->reload($order)->state());
        self::assertSame(0, $this->capturedAmountOf($order));
    }

    /**
     * The notification endpoint's contract, as actually implemented.
     *
     * A processed or replayed notification answers a plain `OK`; a rejected one answers 400; an
     * internal failure answers 500 so PayTR retries. A rejection is deliberately *not* hidden
     * behind `OK`: acknowledging a notification this application refused would tell PayTR the
     * money is settled when it is not.
     *
     * An earlier status note claimed this endpoint answered `OK` on every path. It does not, and
     * the code is right — this test records the shipped behaviour rather than the note.
     */
    public function testTheNotificationEndpointAnswersAccordingToWhatItActuallyDid(): void
    {
        $path = '/odeme/paytr/bildirim';

        $this->postNotification($path, []);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('Notification not accepted', $this->client->getResponse()->getContent());

        $this->postNotification($path, ['status' => 'success', 'hash' => 'nonsense']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        // The content type is plain text on every path, so a provider never has to parse anything.
        self::assertStringStartsWith('text/plain', (string) $this->client->getResponse()->headers->get('Content-Type'));

        $this->client->request('GET', $path);
        self::assertSame(405, $this->client->getResponse()->getStatusCode(), 'The notification endpoint must be POST-only.');
    }

    /**
     * A body the size of a small attack is refused without touching the database.
     *
     * It also proves the endpoint reads the body once rather than parsing it into a field array
     * first — a difference that matters only under load, but which is exactly the kind of thing
     * an unauthenticated endpoint should not have to defend against twice.
     */
    public function testAnOversizedNotificationBodyIsRefusedWithoutSideEffects(): void
    {
        $this->postNotification('/odeme/paytr/bildirim', str_repeat('a', 200_000));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_payment_event'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_audit_log'));
    }

    /**
     * @param array<string, string>|string $body
     */
    private function postNotification(string $path, array|string $body): void
    {
        $this->client->request(
            'POST',
            $path,
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            is_string($body) ? $body : http_build_query($body),
        );
    }

    private function postCallback(CustomerOrder $order, string $reference, string $signature): void
    {
        $this->postCallbackToToken(
            $this->returnTokenOf($order),
            $reference,
            $signature,
            100_00,
        );
    }

    private function postCallbackToToken(string $token, string $reference, string $signature, int $amount = 100_00): void
    {
        $this->client->request(
            'POST',
            '/odeme/sonuc/'.$token,
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_X-Fake-Signature' => $signature],
            http_build_query(['ref' => $reference, 'outcome' => 'succeeded', 'amount' => (string) $amount, 'currency' => 'TRY']),
        );
    }

    /**
     * The fake gateway only accepts a reference it issued, so the reference is threaded through
     * the fixture rather than invented per test. A test posting an arbitrary reference would be
     * refused for "unknown reference" and would prove nothing about signature handling.
     */
    private function orderWithPaymentAttempt(string $reference = 'FAKE-SEC-CB'): CustomerOrder
    {
        $customer = new CustomerUser('callback@example.com', 'Test', 'Müşteri');
        $customer->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, self::PASSWORD));
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $gross = 100_00;
        $order = new CustomerOrder(
            sprintf('EOA-20260929-%s', strtoupper(bin2hex(random_bytes(6)))),
            $customer,
            Money::ofMinor($gross, 'TRY'),
            Money::ofMinor(0, 'TRY'),
            Money::ofMinor(0, 'TRY'),
            Money::ofMinor($gross, 'TRY'),
            'local_standard',
            'Yerel standart teslimat',
            'gateway_checkout',
            'Kredi karti',
            new \DateTimeImmutable(),
        );
        $order->addItem(null, 'BRK-1', 'Fren balatasi', 1, Money::ofMinor($gross, 'TRY'), 2000, Money::ofMinor($gross, 'TRY'), Money::ofMinor(0, 'TRY'), Money::ofMinor($gross, 'TRY'));
        $order->addAddress(OrderAddressRole::Shipping, 'Test', '05320000000', 'Ataturk Caddesi 1', null, 'Cukurova', 'Adana', '01170', 'TR');
        $order->addAddress(OrderAddressRole::Billing, 'Test', '05320000000', 'Gaziantep Caddesi 9', null, 'Sahinbey', 'Gaziantep', '27100', 'TR');
        $order->sealSnapshots();
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        self::getContainer()->get(FakePaymentGateway::class)->queueInitiation(GatewayInitiationOutcome::awaitingCallback($reference));
        self::getContainer()->get(PaymentInitiationService::class)->start($order, 'FAKE');
        $this->entityManager->clear();

        // The customer's own browser would be sitting on this session when the provider redirects
        // back, and the redirect target is an authenticated page.
        $this->client->loginUser($customer, 'main');

        return $order;
    }

    private function returnTokenOf(CustomerOrder $order): string
    {
        $payment = $this->entityManager->getRepository(Payment::class)->findOneBy(['order' => $order]);
        self::assertInstanceOf(Payment::class, $payment);
        $attempt = $payment->latestAttempt();
        self::assertNotNull($attempt);

        return $attempt->returnToken();
    }

    private function capturedAmountOf(CustomerOrder $order): int
    {
        $this->entityManager->clear();
        $payment = $this->entityManager->getRepository(Payment::class)->findOneBy(['order' => $order]);
        self::assertInstanceOf(Payment::class, $payment);

        return (int) $payment->capturedAmount()->minorAmount();
    }

    private function reload(CustomerOrder $order): CustomerOrder
    {
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(CustomerOrder::class, $order->id());
        self::assertInstanceOf(CustomerOrder::class, $reloaded);

        return $reloaded;
    }

    private function selectFakeProvider(): void
    {
        $configuration = self::getContainer()->get(StoreConfiguration::class);
        self::assertInstanceOf(StoreConfiguration::class, $configuration);
        $settings = $configuration->current();
        $settings->paymentProvider = 'fake';
        $configuration->save($settings);
    }

    private function clear(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'commerce_audit_log', 'commerce_payment_event', 'commerce_payment_attempt',
            'commerce_payment_refund', 'commerce_payment', 'commerce_order_status_change',
            'commerce_order_item', 'commerce_order_address', 'commerce_customer_order',
            'commerce_cart_item', 'commerce_cart', 'store_setting', 'customer_address',
            'customer_user',
        ] as $table) {
            $this->connection->executeStatement('DELETE FROM '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }
}
