<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\Shipment;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Shipment;
use App\Entity\Customer\AdminUser;
use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderState;
use App\Module\Payment\SanitizedFailure;
use App\Module\Shipping\ShipmentOrchestrator;
use App\Module\Shipping\ShipmentState;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class ShipmentAdminTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private ShipmentOrchestrator $orchestrator;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $manager = $container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->entityManager = $manager;
        $orchestrator = $container->get(ShipmentOrchestrator::class);
        self::assertInstanceOf(ShipmentOrchestrator::class, $orchestrator);
        $this->orchestrator = $orchestrator;
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testAnAnonymousVisitorCannotReachTheShipmentList(): void
    {
        $this->client->request('GET', '/yeni/admin/gonderiler');

        self::assertResponseRedirects();
    }

    public function testANonAdminCannotReachTheShipmentList(): void
    {
        // An authenticated non-admin identity on the admin firewall still gets nothing.
        $this->client->loginUser(new InMemoryUser(
            'viewer@example.com',
            'test-only-not-used-for-form-login',
            ['ROLE_USER'],
        ), 'admin');

        $this->client->request('GET', '/yeni/admin/gonderiler');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheListShowsTheOrderTheStateAndTheTrackingNumber(): void
    {
        $order = $this->confirmedOrder('list@example.com');
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $this->orchestrator->handOver($shipment, 'VAN-LIST-1', 'admin@example.com');
        $this->loginAsAdmin();

        $this->client->request('GET', '/yeni/admin/gonderiler');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($order->orderNumber(), $content);
        self::assertStringContainsString('VAN-LIST-1', $content);
        self::assertStringContainsString('ready', $content);
    }

    public function testTheListCanBeFilteredByState(): void
    {
        $ready = $this->localShipment('filter-ready@example.com');
        $ready = $this->orchestrator->handOver($ready, 'VAN-R', 'admin@example.com');
        $pending = $this->orchestrator->createForOrder($this->confirmedOrder('filter-pending@example.com')->orderNumber(), 'admin@example.com');
        $this->loginAsAdmin();

        $this->client->request('GET', '/yeni/admin/gonderiler?state=ready');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($ready->orderNumber(), $content);
        self::assertStringNotContainsString($pending->orderNumber(), $content);
    }

    public function testAnUnknownStateFilterIsIgnoredRatherThanFatal(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/yeni/admin/gonderiler?state=not-a-state');

        self::assertResponseIsSuccessful();
    }

    public function testTheDetailPageShowsTrackingAndTheAuditTrailButNeverTheCarriersLabelUrl(): void
    {
        $order = $this->confirmedOrder('detail@example.com', 'carrier_express');
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $shipment->markReady('FAKE-SHIP-1', 'FAKE-TRK-1', new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $order->orderNumber()));

        self::assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('FAKE-TRK-1', $content);
        self::assertStringContainsString('FAKE-SHIP-1', $content);
        self::assertGreaterThan(0, $crawler->filter('table')->count(), 'The shipment history table must render.');
        self::assertGreaterThan(0, $crawler->filter(sprintf('a[href$="/yeni/admin/orders/%s"]', $order->orderNumber()))->count(), 'The shipment page must link back to its order.');
        // Nothing in this phase may turn a carrier's host into a clickable target, and no carrier
        // document URL may be persisted. The page legitimately links only to this application.
        self::assertStringNotContainsString('carrier.test', $content);
        self::assertStringNotContainsString('label/L.pdf', $content);
    }

    public function testACarrierTrackingNumberIsEscapedRatherThanRenderedAsMarkup(): void
    {
        $order = $this->confirmedOrder('xss@example.com', 'carrier_express');
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $shipment->assignTrackingNumber('<script>alert(1)</script>', new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $order->orderNumber()));

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert(1)</script>', $content);
        self::assertStringContainsString('&lt;script&gt;', $content);
    }

    public function testAnOrderPageWithoutAShipmentOffersToCreateOne(): void
    {
        $order = $this->confirmedOrder('create-offer@example.com');
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/orders/%s', $order->orderNumber()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(sprintf('/yeni/admin/gonderiler/%s', $order->orderNumber()), (string) $this->client->getResponse()->getContent());
    }

    public function testTheShipmentPageOffersToCreateTheMissingShipment(): void
    {
        $order = $this->confirmedOrder('create-form@example.com');
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $order->orderNumber()));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->client->getCrawler()->filter(sprintf('form[action$="/%s/olustur"]', $order->orderNumber())));
    }

    public function testACancelledOrderIsNeverOfferedAShipment(): void
    {
        $order = $this->confirmedOrder('cancelled-order@example.com');
        $order->transitionTo(OrderState::Cancelled);
        $this->entityManager->flush();
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $order->orderNumber()));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->client->getCrawler()->filter(sprintf('form[action$="/%s/olustur"]', $order->orderNumber())));
    }

    public function testCreatingAShipmentTwiceLeavesOneShipmentAndNoError(): void
    {
        // The plan's central requirement, exercised through the UI a double-click actually uses.
        $order = $this->confirmedOrder('double@example.com');
        $this->loginAsAdmin();
        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $order->orderNumber()));
        $form = $this->client->getCrawler()->filter(sprintf('form[action$="/%s/olustur"]', $order->orderNumber()))->form();
        $token = $form->getPhpValues()['shipment_create']['_token'] ?? null;
        self::assertIsString($token);

        $this->post(sprintf('/yeni/admin/gonderiler/%s/olustur', $order->orderNumber()), ['shipment_create' => ['_token' => $token]]);
        self::assertResponseRedirects(sprintf('/yeni/admin/gonderiler/%s', $order->orderNumber()));
        $this->post(sprintf('/yeni/admin/gonderiler/%s/olustur', $order->orderNumber()), ['shipment_create' => ['_token' => $token]]);

        self::assertResponseRedirects(sprintf('/yeni/admin/gonderiler/%s', $order->orderNumber()));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_shipment WHERE order_id = ?', [$order->id()]));
        $this->client->followRedirect();
        self::assertStringContainsString('yeni gönderi oluşturulmadı', (string) $this->client->getResponse()->getContent());
    }

    public function testCreatingAShipmentRequiresACsrfToken(): void
    {
        $order = $this->confirmedOrder('create-csrf@example.com');
        $this->loginAsAdmin();

        $this->post(sprintf('/yeni/admin/gonderiler/%s/olustur', $order->orderNumber()), ['shipment_create' => ['_token' => 'not-a-real-token']]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_shipment'));
    }

    public function testAHandDeliveredParcelIsTakenThroughHandoverTransitAndDelivery(): void
    {
        $shipment = $this->localShipment('hand@example.com');
        $this->loginAsAdmin();
        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $shipment->orderNumber()));
        $crawler = $this->client->getCrawler();
        $handover = $crawler->filter('form[action$="/teslimata-hazir"]')->form();
        $handover['shipment_handover[trackingNumber]'] = 'VAN-HAND-1';
        $this->client->submit($handover);

        self::assertResponseRedirects(sprintf('/yeni/admin/gonderiler/%s', $shipment->orderNumber()));
        $shipment = $this->reload($shipment);
        self::assertSame(ShipmentState::Ready, $shipment->state());
        self::assertSame('VAN-HAND-1', $shipment->trackingNumber());

        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $shipment->orderNumber()));
        $this->client->submit($this->client->getCrawler()->filter('form[action$="/yolda"] button')->form());
        self::assertSame(ShipmentState::InTransit, $this->reload($shipment)->state());

        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $shipment->orderNumber()));
        $this->client->submit($this->client->getCrawler()->filter('form[action$="/teslim"] button')->form());
        $delivered = $this->reload($shipment);
        self::assertSame(ShipmentState::Delivered, $delivered->state());
        self::assertNotNull($delivered->deliveredAt());
    }

    public function testAnInTransitParcelCannotBeCancelledByHandEvenWithAValidToken(): void
    {
        $shipment = $this->localShipment('no-cancel@example.com');
        $this->orchestrator->handOver($shipment, 'VAN-C', 'admin@example.com');
        $this->orchestrator->markInTransit($this->reload($shipment), 'admin@example.com');
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $shipment->orderNumber()));
        $content = (string) $this->client->getResponse()->getContent();
        // Not offered...
        self::assertStringNotContainsString('/iptal', $content);

        // ...and refused with 403 when a crafted request walks past the hidden button, using a real
        // token taken from a cancellable shipment rendered in the same session.
        $token = $this->cancellationTokenFor($this->localShipment('token-source@example.com'));
        $this->post(sprintf('/yeni/admin/gonderiler/%s/iptal', $shipment->orderNumber()), [
            'shipment_cancel' => ['reason' => 'Yanlış adres', '_token' => $token],
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(ShipmentState::InTransit, $this->reload($shipment)->state(), 'A parcel on the road must not be cancellable.');
    }

    public function testACancellationNeedsAReason(): void
    {
        $shipment = $this->localShipment('cancel-reason@example.com');
        $this->loginAsAdmin();
        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $shipment->orderNumber()));
        $form = $this->client->getCrawler()->filter('form[action$="/iptal"]')->form();
        $form['shipment_cancel[reason]'] = '   ';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('İptal nedeni zorunludur', (string) $this->client->getResponse()->getContent());
        self::assertNotSame(ShipmentState::Cancelled, $this->reload($shipment)->state());
    }

    public function testACancellationRecordsItsReasonInTheShipmentHistory(): void
    {
        $shipment = $this->localShipment('cancel-recorded@example.com');
        $this->loginAsAdmin();
        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $shipment->orderNumber()));
        $form = $this->client->getCrawler()->filter('form[action$="/iptal"]')->form();
        $form['shipment_cancel[reason]'] = 'Müşteri siparişi iptal etti';
        $this->client->submit($form);

        $cancelled = $this->reload($shipment);
        self::assertSame(ShipmentState::Cancelled, $cancelled->state());
        $details = array_map(static fn ($event): ?string => $event->detail(), $cancelled->events());
        self::assertContains('Müşteri siparişi iptal etti', $details);
        $this->client->followRedirect();
        self::assertStringContainsString('Müşteri siparişi iptal etti', (string) $this->client->getResponse()->getContent());
    }

    public function testACarrierBackedShipmentIsNeverHandedOverByHand(): void
    {
        $order = $this->confirmedOrder('carrier-no-handover@example.com', 'carrier_express');
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $order->orderNumber()));

        self::assertStringNotContainsString('/teslimata-hazir', (string) $this->client->getResponse()->getContent());
        self::assertSame(ShipmentState::Pending, $this->reload($shipment)->state());
    }

    public function testAFailedCarrierShipmentOffersAnOperatorRetry(): void
    {
        $order = $this->confirmedOrder('failed@example.com', 'carrier_express');
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $shipment->markFailed(SanitizedFailure::fromProvider('address_invalid', 'no such district', null), new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $order->orderNumber()));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->client->getCrawler()->filter('form[action$="/yeniden-dene"]'));
    }

    public function testARetryIsOfferedOnlyWhileTheCarrierIsRefusing(): void
    {
        $order = $this->confirmedOrder('not-failed@example.com', 'carrier_express');
        $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $order->orderNumber()));

        self::assertCount(0, $this->client->getCrawler()->filter('form[action$="/yeniden-dene"]'));
    }

    public function testCarrierStatusAndLabelActionsAppearOnlyOnceTheCarrierAcceptedTheParcel(): void
    {
        $pending = $this->orchestrator->createForOrder($this->confirmedOrder('pending-actions@example.com', 'carrier_express')->orderNumber(), 'admin@example.com');
        $this->loginAsAdmin();
        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $pending->orderNumber()));
        self::assertStringNotContainsString('/durum', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('/etiket', (string) $this->client->getResponse()->getContent());

        $accepted = $this->confirmedOrder('accepted-actions@example.com', 'carrier_express');
        $shipment = $this->orchestrator->createForOrder($accepted->orderNumber(), 'admin@example.com');
        $shipment->markReady('FAKE-SHIP-9', 'FAKE-TRK-9', new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $accepted->orderNumber()));

        self::assertCount(1, $this->client->getCrawler()->filter('form[action$="/durum"]'));
        self::assertCount(1, $this->client->getCrawler()->filter('form[action$="/etiket"]'));
    }

    public function testRefreshingAStatusFromTheCarrierMovesTheParcel(): void
    {
        $order = $this->confirmedOrder('status@example.com', 'carrier_express');
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $shipment->markReady('FAKE-SHIP-S', 'FAKE-TRK-S', new \DateTimeImmutable());
        $this->entityManager->flush();
        $provider = self::getContainer()->get(\App\Module\Shipping\FakeShippingProvider::class);
        self::assertInstanceOf(\App\Module\Shipping\FakeShippingProvider::class, $provider);
        $provider->queueStatus(\App\Module\Shipping\Gateway\ShipmentStatusReport::reporting(ShipmentState::InTransit, 'FAKE-TRK-S2', 'Yola çıktı'));
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $order->orderNumber()));
        $this->client->submit($this->client->getCrawler()->filter('form[action$="/durum"] button')->form());
        self::assertResponseRedirects(sprintf('/yeni/admin/gonderiler/%s', $order->orderNumber()));

        $updated = $this->reload($shipment);
        self::assertSame(ShipmentState::InTransit, $updated->state());
        self::assertSame('FAKE-TRK-S2', $updated->trackingNumber());
    }

    public function testTheShipmentListIsReachableFromTheAdminNavigation(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/yeni/admin');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/yeni/admin/gonderiler', (string) $this->client->getResponse()->getContent());
    }

    public function testAShipmentForAnUnknownOrderIsNotFound(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/yeni/admin/gonderiler/EOA-20260928-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    public function testANonAdminCannotActOnAShipmentEvenWithAToken(): void
    {
        $shipment = $this->localShipment('forbidden@example.com');
        $this->client->loginUser(new InMemoryUser('viewer@example.com', 'test-only-not-used-for-form-login', ['ROLE_USER']), 'admin');

        $this->post(sprintf('/yeni/admin/gonderiler/%s/yolda', $shipment->orderNumber()), ['shipment_transit' => ['_token' => 'anything']]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(ShipmentState::Pending, $this->reload($shipment)->state());
    }

    public function testAnOverlongTrackingNumberIsRefusedRatherThanSilentlyTruncated(): void
    {
        // The constraint is on the form, so the operator is told. Silently cutting it would leave a
        // tracking number in the database that no longer matches what was typed.
        $shipment = $this->localShipment('long-tracking@example.com');
        $this->loginAsAdmin();
        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $shipment->orderNumber()));
        $form = $this->client->getCrawler()->filter('form[action$="/teslimata-hazir"]')->form();
        $form['shipment_handover[trackingNumber]'] = str_repeat('9', 200);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ShipmentState::Pending, $this->reload($shipment)->state());
    }

    public function testACarrierBackedShipmentIsNeverOfferedOrAcceptedForManualStateChanges(): void
    {
        // The store's word must not override the carrier's, or a customer can be told their parcel
        // arrived while the courier still has it.
        $order = $this->confirmedOrder('carrier-confinement@example.com', 'carrier_express');
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $shipment->markReady('FAKE-SHIP-CF', 'FAKE-TRK-CF', new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $order->orderNumber()));
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('/yolda', $content);
        self::assertStringNotContainsString('/teslim"', $content);
        self::assertCount(0, $this->client->getCrawler()->filter('form[action$="/yolda"]'));
        self::assertCount(0, $this->client->getCrawler()->filter('form[action$="/teslim"]'));

        // And refused when a crafted request walks past the hidden buttons, using a real token taken
        // from a hand-fulfilled parcel rendered in the same session.
        $token = $this->actionTokenFor($this->handedOverShipment('token-source-handover@example.com'), 'shipment_transit');
        $this->post(sprintf('/yeni/admin/gonderiler/%s/yolda', $order->orderNumber()), ['shipment_transit' => ['_token' => $token]]);

        self::assertSame(ShipmentState::Ready, $this->reload($shipment)->state());
    }

    public function testEveryOperatorActionIsAttributedToTheAdminWhoTookIt(): void
    {
        // The trail that exists to answer "who moved this parcel" is worthless if every row is
        // anonymous. A provider-sourced row is legitimately anonymous; an operator's is not.
        $shipment = $this->localShipment('actor@example.com');
        $this->orchestrator->handOver($shipment, 'VAN-ACTOR', 'admin@example.com');
        $this->orchestrator->markInTransit($this->reload($shipment), 'admin@example.com');
        $this->orchestrator->markDelivered($this->reload($shipment), 'admin@example.com');

        $actors = array_map(static fn ($event): ?string => $event->actorEmail(), $this->reload($shipment)->events());

        self::assertSame(['admin@example.com', 'admin@example.com', 'admin@example.com', 'admin@example.com'], $actors);
    }

    public function testAParcelTheCarrierNeverTookCanStillBeCancelledFromTheScreen(): void
    {
        $order = $this->confirmedOrder('cancel-pending-carrier-ui@example.com', 'carrier_express');
        $shipment = $this->orchestrator->createForOrder($order->orderNumber(), 'admin@example.com');
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $order->orderNumber()));
        $form = $this->client->getCrawler()->filter('form[action$="/iptal"]')->form();
        $form['shipment_cancel[reason]'] = 'Müşteri siparişten vazgeçti';
        $this->client->submit($form);

        self::assertResponseRedirects(sprintf('/yeni/admin/gonderiler/%s', $order->orderNumber()));
        self::assertSame(ShipmentState::Cancelled, $this->reload($shipment)->state());
    }

    private function handedOverShipment(string $email): Shipment
    {
        $shipment = $this->localShipment($email);

        return $this->orchestrator->handOver($this->reload($shipment), 'VAN-TOKEN-SOURCE', 'admin@example.com');
    }

    private function actionTokenFor(Shipment $shipment, string $block): string
    {
        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $shipment->orderNumber()));
        $token = $this->client->getCrawler()->filter(sprintf('input[name="%s[_token]"]', $block))->attr('value');

        self::assertIsString($token);

        return $token;
    }

    /** @param array<string, mixed> $parameters */
    private function post(string $url, array $parameters): void
    {
        $this->client->request('POST', $url, $parameters);
    }

    private function cancellationTokenFor(Shipment $shipment): string
    {
        $this->client->request('GET', sprintf('/yeni/admin/gonderiler/%s', $shipment->orderNumber()));
        $token = $this->client->getCrawler()->filter('input[name="shipment_cancel[_token]"]')->attr('value');

        self::assertIsString($token);

        return $token;
    }

    private function loginAsAdmin(): void
    {
        $admin = new AdminUser('admin@example.com');
        $admin->setPassword('test-password-hash');
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        $this->client->loginUser($admin, 'admin');
    }

    private function reload(Shipment $shipment): Shipment
    {
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Shipment::class, $shipment->id());

        self::assertInstanceOf(Shipment::class, $reloaded);

        return $reloaded;
    }

    private function localShipment(string $email): Shipment
    {
        return $this->orchestrator->createForOrder($this->confirmedOrder($email)->orderNumber(), 'admin@example.com');
    }

    private function confirmedOrder(string $email, string $methodKey = 'local_standard'): CustomerOrder
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
            $methodKey,
            'Yerel standart teslimat',
            'local_manual',
            'Yerel manuel doğrulama',
            new \DateTimeImmutable(),
        );
        $order->addItem(null, 'BRK-1', 'Fren balatası', 2, Money::ofMinor(15_000, 'TRY'), 0, $gross, $zero, $gross);
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
