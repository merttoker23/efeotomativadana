<?php

declare(strict_types=1);

namespace App\Tests\Controller\Storefront;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Payment;
use App\Entity\Commerce\ReturnRequest;
use App\Entity\Commerce\Shipment;
use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderState;
use App\Module\Payment\PaymentState;
use App\Module\Payment\SanitizedFailure;
use App\Module\Returns\ReturnState;
use App\Module\Shipping\ShipmentState;
use App\Shared\Money\Money;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The customer's own view of what they bought, and of any return they opened.
 *
 * Two properties matter more than the markup here: a customer can only ever read their own rows,
 * and what a screen shows about money and parcels comes from the aggregates rather than from
 * anything the request supplied.
 */
final class CustomerOrdersTest extends WebTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->clear();
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
        $this->entityManager->clear();
        parent::tearDown();
    }

    public function testAnAnonymousVisitorIsRedirectedFromOrdersAndReturns(): void
    {
        $customer = $this->createCustomer('orders@example.com');
        $order = $this->confirmedOrder($customer);

        $this->client->request('GET', '/hesabim/siparisler');
        self::assertResponseRedirects('/giris');

        $this->client->request('GET', '/hesabim/siparisler/'.$order->orderNumber());
        self::assertResponseRedirects('/giris');

        $this->client->request('GET', '/hesabim/iadeler');
        self::assertResponseRedirects('/giris');
    }

    public function testACustomerSeesTheirOwnOrderListNewestFirst(): void
    {
        $customer = $this->createCustomer('orders@example.com');
        $older = $this->confirmedOrder($customer, 'EOA-20260920-AAAA00000001', new \DateTimeImmutable('2026-09-20 10:00:00'));
        $newer = $this->confirmedOrder($customer, 'EOA-20260928-BBBB00000002', new \DateTimeImmutable('2026-09-28 10:00:00'));
        $stranger = $this->createCustomer('stranger@example.com');
        $this->confirmedOrder($stranger, 'EOA-20260928-CCCC00000003', new \DateTimeImmutable('2026-09-28 11:00:00'));

        $this->client->loginUser($customer, 'main');
        $crawler = $this->client->request('GET', '/hesabim/siparisler');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Siparişlerim');
        self::assertSelectorTextContains('main', $newer->orderNumber());
        self::assertSelectorTextContains('main', $older->orderNumber());
        self::assertSelectorTextNotContains('main', 'EOA-20260928-CCCC00000003', 'A customer must never see another customer\'s order.');

        $rows = $crawler->filter('[data-testid="order-row"]');
        self::assertCount(2, $rows);
        self::assertSelectorNotExists('.order-card-amount small');
        self::assertSelectorTextNotContains('.order-card-amount', 'Toplam');
        self::assertCount(2, $crawler->filter('details.order-disclosure > summary.order-card-summary'));
        self::assertStringContainsString($newer->orderNumber(), $rows->first()->text());
    }

    public function testTheOrderListIsPaginatedAndThePageLinksKeepTheCustomerOnTheirOwnOrders(): void
    {
        $customer = $this->createCustomer('paged@example.com');
        // One more than a page holds, so a second page genuinely exists rather than being asserted
        // against a list that would have fitted on one anyway.
        for ($i = 0; $i < 11; ++$i) {
            $this->confirmedOrder($customer, sprintf('EOA-20260928-DDDD%08d', $i), new \DateTimeImmutable(sprintf('2026-09-%02d 10:00:00', 1 + $i % 28)));
        }

        $this->client->loginUser($customer, 'main');
        $crawler = $this->client->request('GET', '/hesabim/siparisler', ['page' => 1]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.pagination');
        self::assertCount(10, $crawler->filter('[data-testid="order-row"]'));

         $crawler = $this->client->request('GET', '/hesabim/siparisler', ['page' => 2]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="order-row"]'));
    }

    public function testUnpaidOrdersOfferRecoveryInTheCollapsedCardAndPaymentSummary(): void
    {
        $customer = $this->createCustomer('recovery@example.com');
        $cases = [
            [null, OrderState::Placed, true, 'Ödeme bekleniyor'],
            [PaymentState::Pending, OrderState::Placed, true, 'Ödeme bekleniyor'],
            [PaymentState::RequiresAction, OrderState::Placed, true, 'Ödeme bekleniyor'],
            [PaymentState::Failed, OrderState::Placed, true, 'Ödeme başarısız'],
            [PaymentState::Succeeded, OrderState::Confirmed, false, 'Ödendi'],
            [PaymentState::Refunded, OrderState::Confirmed, false, 'İade edildi'],
            [PaymentState::Pending, OrderState::Cancelled, false, 'Ödeme bekleniyor'],
            [PaymentState::Pending, OrderState::Completed, false, 'Ödeme bekleniyor'],
        ];
        $orders = [];
        foreach ($cases as $index => [$state, $orderState, $canPay, $label]) {
            $order = $this->placedOrder($customer, sprintf('EOA-20261005-ABCD%08d', $index));
            if (OrderState::Completed === $orderState) {
                $order->transitionTo(OrderState::Confirmed);
            }
            if (OrderState::Placed !== $orderState) {
                $order->transitionTo($orderState);
            }
            if (null !== $state) {
                $payment = Payment::start($order, 'paytr', $order->grandTotal(), new \DateTimeImmutable());
                $payment->beginAttempt('presentation-'.$order->orderNumber());
                (new \ReflectionProperty(Payment::class, 'state'))->setValue($payment, $state);
                $this->entityManager->persist($payment);
            }
            $orders[] = [$order->orderNumber(), $canPay, $label];
        }
        $this->entityManager->flush();
        $this->client->loginUser($customer, 'main');
        $crawler = $this->client->request('GET', '/hesabim/siparisler');
        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('summary .order-payment-link'));
        self::assertSame(array_fill(0, 4, 'Ödemeyi Tamamla'), $crawler->filter('summary .order-payment-link')->each(static fn ($link): string => $link->text()));
        foreach ($orders as $index => [$number, $canPay, $label]) {
            $link = $crawler->filter(sprintf('a.order-payment-link[href="/odeme/%s"]', $number));
            self::assertCount($canPay ? 1 : 0, $link);
            $this->client->request('GET', '/odeme/'.$number);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.payment-status-badge', $label);
            self::assertSelectorTextNotContains('main', 'requires_action');
            self::assertSelectorTextNotContains('main', 'pending');
            self::assertSelectorTextNotContains('main', 'failed');
            self::assertSelectorCount($canPay ? 1 : 0, '.payment-action-primary');
            self::assertSelectorCount($canPay && $index > 0 ? 1 : 0, '.payment-action-danger');
            if ($canPay) {
                self::assertSelectorExists('form input[name="_token"]');
                self::assertSelectorTextContains('.payment-action-primary', 'Ödemeyi tamamla');
            }
            self::assertSelectorTextContains('.payment-total dt', 'Toplam');
            self::assertSelectorExists('.payment-total dd');
        }
    }

    /**
     * The order list resolves each order's payment and shipment. A per-order lookup would make
     * the query count grow with the page size, so the count is compared across two page fills
     * (five orders and ten orders) as well as against a documented ceiling.
     *
     * The ceiling is 11, including one batched thumbnail read. A fetch-joined collection makes
     * Paginator issue a root-id query before the count and the rows, so the list costs three
     * queries instead of two — and buys back the one-items query per order it replaced. The
     * rest is one batched payment lookup, one batched shipment lookup, and a small fixed set for
     * the session user, the store settings, the navigation and the header cart summary.
     */
    public function testOrderListDoesNotQueryPerOrderForPaymentsAndShipments(): void
    {
        $customer = $this->createCustomer('nplus1@example.com');
        $this->client->loginUser($customer, 'main');
        $customerId = $customer->id();
        self::assertNotNull($customerId);

        for ($i = 1; $i <= 5; ++$i) {
            $order = $this->confirmedOrder($customer, sprintf('EOA-20260930-A1B2%08d', $i), new \DateTimeImmutable(sprintf('2026-09-%02d 10:00:00', $i)));
            $this->settlePayment($order);
            $this->shippedParcel($order, ShipmentState::InTransit, sprintf('TR-%03d', $i));
        }
        // Warm the request once before either measurement is taken. The first request after
        // loginUser() reuses the token that loginUser() just put in the session, while every
        // later one re-reads it from storage and re-hydrates the customer — one extra query that
        // belongs to authentication, not to the list, and which would otherwise make the first
        // measurement look a query cheaper than the second for no reason connected to orders.
        $this->profileOrderList($customerId);

        $withFiveOrders = $this->profileOrderList($customerId);

        // Profiling clears the identity map so the request reads the database rather than the
        // fixtures it just wrote. That also detaches the customer, so the second batch needs it
        // re-attached before a new order can reference it.
        $customer = $this->reloadCustomer($customerId);

        for ($i = 6; $i <= 10; ++$i) {
            $order = $this->confirmedOrder($customer, sprintf('EOA-20260930-A1B2%08d', $i), new \DateTimeImmutable(sprintf('2026-09-%02d 10:00:00', $i)));
            $this->settlePayment($order);
            $this->shippedParcel($order, ShipmentState::InTransit, sprintf('TR-%03d', $i));
        }
        $this->entityManager->clear();

        $withTenOrders = $this->profileOrderList($customerId);

        self::assertSame($withFiveOrders, $withTenOrders, 'The order list must not query per order for payments and shipments.');
        // Twelve: the page as it was at eleven, plus the footer's single bounded read of the
        // published information pages. The footer is on this page like on every other, so its cost
        // is here once rather than per order — which is what the assertion above already proves.
        self::assertLessThanOrEqual(12, $withFiveOrders);
    }

    /**
     * Detaches every entity so the profiled request cannot answer from the identity map, then
     * returns the database query count for one rendered page of the order list.
     */
    private function profileOrderList(int $customerId): int
    {
        $this->entityManager->clear();
        $this->reloadCustomer($customerId);

        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $debugData);
        $debugData->reset();
        $this->client->enableProfiler();

        $this->client->request('GET', '/hesabim/siparisler');

        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(1, $this->client->getCrawler()->filter('[data-testid="order-row"]')->count());
        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);
        $database = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $database);

        return $database->getQueryCount();
    }

    public function testTheOrderListSaysSoWhenThereAreNoOrders(): void
    {
        $customer = $this->createCustomer('empty@example.com');
        $this->client->loginUser($customer, 'main');

        $this->client->request('GET', '/hesabim/siparisler');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Henüz siparişiniz yok');
    }

    public function testOrderCardsCollapseAndUseCurrentImagesWithHistoricalLineValues(): void
    {
        $customer = $this->createCustomer('order-images@example.com');
        $order = $this->visualOrder($customer, 'EOA-20261004-AAAA00000001');
        $this->entityManager->clear();
        $this->client->loginUser($this->reloadCustomer($customer->id()), 'main');
        $crawler = $this->client->request('GET', '/hesabim/siparisler');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('details[data-testid="order-row"]:not([open]) > summary');
        self::assertSelectorTextContains('summary', '3 ürün · 4 adet');
        self::assertSelectorTextNotContains('summary', 'Tarihsel filtre');
        self::assertCount(3, $crawler->filter('.order-products .order-product'));
        self::assertSelectorTextContains('.order-products', 'Tarihsel filtre');
        self::assertSelectorTextContains('.order-products', 'HISTORICAL-SKU');
        self::assertSelectorTextNotContains('.order-products', 'Güncel katalog adı');
        self::assertStringContainsString('/uploads/products/order-primary.jpg', (string) $crawler->filter('.order-products img')->first()->attr('src'));
        self::assertStringContainsString('product-placeholder', (string) $crawler->filter('.order-products img')->eq(1)->attr('src'));
        self::assertStringContainsString('product-placeholder', (string) $crawler->filter('.order-products img')->eq(2)->attr('src'));
        self::assertSelectorTextContains('.order-products', '200,00 TL');

        $crawler = $this->client->request('GET', '/hesabim/siparisler/'.$order->orderNumber());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/uploads/products/order-primary.jpg', (string) $crawler->filter('.order-products img')->first()->attr('src'));
        self::assertSelectorTextContains('.order-products', 'HISTORICAL-SKU');
        self::assertSelectorTextContains('[data-testid="order-total"]', '400,00 TL');
        self::assertCount(2, $crawler->filter('.order-address-card'));
    }

    public function testOrderImagesDoNotAddQueriesAsProductAndOrderCountsGrow(): void
    {
        $customer = $this->createCustomer('visual-nplus1@example.com');
        $this->client->loginUser($customer, 'main');
        $id = $customer->id();
        self::assertNotNull($id);
        for ($i = 1; $i <= 5; ++$i) {
            $this->visualOrder($customer, sprintf('EOA-20261004-BBBB%08d', $i));
        }
        $this->profileOrderList($id);
        $five = $this->profileOrderList($id);
        $customer = $this->reloadCustomer($id);
        for ($i = 6; $i <= 10; ++$i) {
            $this->visualOrder($customer, sprintf('EOA-20261004-BBBB%08d', $i));
        }
        self::assertSame($five, $this->profileOrderList($id), 'Image reads must stay constant with distinct products across multiple orders.');
        self::assertLessThanOrEqual(12, $five);
    }

    private function visualOrder(CustomerUser $customer, string $number): CustomerOrder
    {
        $customer = $this->reloadCustomer($customer->id());
        $product = new \App\Entity\Catalog\Product('UI-ORDER-'.$number, 'Güncel katalog adı', 'ui-order-'.strtolower($number));
        $product->addImage('/uploads/products/order-secondary.jpg', null, 10);
        $product->addImage('/uploads/products/order-primary.jpg', null, 0);
        $product->addImage('/uploads/products/order-tied.jpg', null, 0);
        $imageless = new \App\Entity\Catalog\Product('UI-ORDER-EMPTY-'.$number, 'Görselsiz ürün', 'ui-order-empty-'.strtolower($number));
        $deleted = new \App\Entity\Catalog\Product('UI-ORDER-DELETED-'.$number, 'Silinecek ürün', 'ui-order-deleted-'.strtolower($number));
        foreach ([$product, $imageless, $deleted] as $entity) {
            $this->entityManager->persist($entity);
        }
        $order = new CustomerOrder($number, $customer, Money::ofMinor(40_000, 'TRY'), Money::ofMinor(0, 'TRY'), Money::ofMinor(0, 'TRY'), Money::ofMinor(40_000, 'TRY'), 'local_standard', 'Standart teslimat', 'gateway_checkout', 'Kredi kartı', new \DateTimeImmutable());
        foreach ([[$product, 'HISTORICAL-SKU', 'Tarihsel filtre', 2], [$imageless, 'EMPTY-SKU', 'Görselsiz ürün', 1], [$deleted, 'DELETED-SKU', 'Silinmiş ürün', 1]] as [$catalogProduct, $sku, $name, $quantity]) {
            $order->addItem($catalogProduct, $sku, $name, $quantity, Money::ofMinor(10_000, 'TRY'), 0, Money::ofMinor(10_000 * $quantity, 'TRY'), Money::ofMinor(0, 'TRY'), Money::ofMinor(10_000 * $quantity, 'TRY'));
        }
        foreach ([OrderAddressRole::Shipping, OrderAddressRole::Billing] as $role) {
            $order->addAddress($role, 'Efe Yılmaz', '05320000000', 'Atatürk Caddesi 1', null, 'Çukurova', 'Adana', '01170', 'TR');
        }
        $order->sealSnapshots();
        $this->entityManager->persist($order);
        $this->entityManager->flush();
        $this->entityManager->remove($deleted);
        $this->entityManager->flush();
        // Drop the in-memory relation to the removed product; the next read must observe SET NULL.
        $this->entityManager->clear();

        return $order;
    }

    public function testTheOrderDetailShowsTheItemsTotalsAndAddressesFromTheSealedSnapshot(): void
    {
        $customer = $this->createCustomer('detail@example.com');
        $order = $this->confirmedOrder($customer);

        $this->client->loginUser($customer, 'main');
        $crawler = $this->client->request('GET', '/hesabim/siparisler/'.$order->orderNumber());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', $order->orderNumber());
        self::assertSelectorTextContains('main', 'Filtre');
        self::assertSelectorTextContains('main', 'SKU-1');
        self::assertSelectorTextContains('main', 'Atatürk Caddesi 1');
        self::assertSelectorTextContains('main', 'Teslimat Adresi');
        self::assertSelectorTextContains('main', 'Fatura Adresi');
        self::assertSelectorTextContains('main', '3.703,68');
        self::assertSelectorExists('[data-testid="order-total"]');
    }

    public function testTheOrderDetailShowsThePaymentSummary(): void
    {
        $customer = $this->createCustomer('paid@example.com');
        $order = $this->confirmedOrder($customer);
        $this->settlePayment($order);

        $this->client->loginUser($customer, 'main');
        $crawler = $this->client->request('GET', '/hesabim/siparisler/'.$order->orderNumber());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="payment-summary"]');
        self::assertSelectorTextContains('[data-testid="payment-summary"]', 'Ödendi');
    }

    public function testTheOrderDetailShowsTheShipmentAndTrackingSummary(): void
    {
        $customer = $this->createCustomer('shipped@example.com');
        $order = $this->confirmedOrder($customer);
        $shipment = $this->shippedParcel($order, ShipmentState::InTransit, 'TR-ABC-123');

        $this->client->loginUser($customer, 'main');
        $crawler = $this->client->request('GET', '/hesabim/siparisler/'.$order->orderNumber());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="shipment-summary"]');
        self::assertSelectorTextContains('[data-testid="shipment-summary"]', 'TR-ABC-123');
        self::assertSelectorTextContains('[data-testid="shipment-summary"]', 'Yolda');
        self::assertStringContainsString($order->orderNumber(), $shipment->orderNumber());
    }

    public function testTheOrderDetailSaysWhenThereIsNoParcelYet(): void
    {
        $customer = $this->createCustomer('noship@example.com');
        $order = $this->confirmedOrder($customer);

        $this->client->loginUser($customer, 'main');
        $crawler = $this->client->request('GET', '/hesabim/siparisler/'.$order->orderNumber());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="shipment-summary"]');
    }

    /**
     * The customer must not be shown a provider's raw failure text: it is written for an operator
     * and routinely quotes provider internals.
     */
    public function testTheOrderDetailNeverShowsRawProviderFailureText(): void
    {
        $customer = $this->createCustomer('rawfailure@example.com');
        $order = $this->confirmedOrder($customer);
        $payment = Payment::start($order, 'paytr', $order->grandTotal(), new \DateTimeImmutable('2026-09-28 10:00:00'));
        $attempt = $payment->beginAttempt('k1');
        $payment->markFailed($attempt, SanitizedFailure::fromProvider('paytr_error_005', 'merchant_oid ile basarili odeme bulunamadi', null), new \DateTimeImmutable('2026-09-28 10:05:00'));
        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        $this->client->loginUser($customer, 'main');
        $crawler = $this->client->request('GET', '/hesabim/siparisler/'.$order->orderNumber());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', 'merchant_oid');
    }

    public function testOneCustomerCannotOpenAnotherCustomersOrderByGuessingItsNumber(): void
    {
        $owner = $this->createCustomer('owner@example.com');
        $stranger = $this->createCustomer('stranger@example.com');
        $order = $this->confirmedOrder($owner);

        $this->client->loginUser($stranger, 'main');
        $this->client->request('GET', '/hesabim/siparisler/'.$order->orderNumber());

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnUnknownOrderNumberIsAlsoA404(): void
    {
        $customer = $this->createCustomer('guess@example.com');
        $this->client->loginUser($customer, 'main');

        $this->client->request('GET', '/hesabim/siparisler/EOA-20260928-FFFFFFFFFF');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAMalformedOrderNumberIsNotRoutedAtAll(): void
    {
        $customer = $this->createCustomer('malformed@example.com');
        $this->client->loginUser($customer, 'main');

        $this->client->request('GET', '/hesabim/siparisler/../../../etc/passwd');

        self::assertResponseStatusCodeSame(404);
    }

    public function testACustomerCanRaiseAReturnFromTheOrderPageAndSeesItInTheirReturnList(): void
    {
        $customer = $this->createCustomer('returning@example.com');
        $order = $this->confirmedOrder($customer);
        $item = $order->items()[0];

        $this->client->loginUser($customer, 'main');
        $crawler = $this->client->request('GET', '/hesabim/siparisler/'.$order->orderNumber().'/iade');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'İade talebi');
        self::assertSelectorExists('form[name="customer_return_request"]');

        $this->client->submit($crawler->selectButton('İade talebini gönder')->form([
            'customer_return_request[customerReason]' => 'Filtre kutusu ezilmiş olarak geldi.',
            'customer_return_request[line_0][quantity]' => 1,
            'customer_return_request[line_0][reason]' => 'Kutu ezik.',
        ]));

        self::assertResponseRedirects('/hesabim/iadeler');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_return_request WHERE order_id = ?', [$order->id()]));

        $crawler = $this->client->request('GET', '/hesabim/iadeler');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'İadelerim');
        self::assertSelectorTextContains('main', $order->orderNumber());
    }

    public function testAReturnRequestAskingForMoreThanWasPurchasedIsRefusedWithAFormError(): void
    {
        $customer = $this->createCustomer('toomany@example.com');
        $order = $this->confirmedOrder($customer, quantity: 2);

        $this->client->loginUser($customer, 'main');
        $crawler = $this->client->request('GET', '/hesabim/siparisler/'.$order->orderNumber().'/iade');
        $this->client->submit($crawler->selectButton('İade talebini gönder')->form([
            'customer_return_request[customerReason]' => 'Hepsi bozuk.',
            'customer_return_request[line_0][quantity]' => 9,
            'customer_return_request[line_0][reason]' => 'Hepsi bozuk.',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.account-form ul li');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_return_request WHERE order_id = ?', [$order->id()]));
    }

    public function testAReturnWithAReasonButNoSelectedItemsShowsAnAccessibleServerError(): void
    {
        $customer = $this->createCustomer('empty-selection@example.com');
        $order = $this->confirmedOrder($customer);
        $this->client->loginUser($customer, 'main');
        $crawler = $this->client->request('GET', '/hesabim/siparisler/'.$order->orderNumber().'/iade');
        $this->client->submit($crawler->selectButton('İade talebini gönder')->form([
            'customer_return_request[customerReason]' => 'Kutusu hasarlı.',
            'customer_return_request[line_0][quantity]' => 0,
            'customer_return_request[line_0][reason]' => '',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.account-form [role="alert"]', 'İade talebiniz oluşturulamadı. Lütfen bilgileri kontrol edin.');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_return_request WHERE order_id = ?', [$order->id()]));
    }

    public function testAReturnRequestWithNoLineAndNoReasonIsRefused(): void
    {
        $customer = $this->createCustomer('noline@example.com');
        $order = $this->confirmedOrder($customer);

        $this->client->loginUser($customer, 'main');
        $crawler = $this->client->request('GET', '/hesabim/siparisler/'.$order->orderNumber().'/iade');
        $this->client->submit($crawler->selectButton('İade talebini gönder')->form([
            'customer_return_request[customerReason]' => '',
            'customer_return_request[line_0][quantity]' => 0,
            'customer_return_request[line_0][reason]' => '',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_return_request WHERE order_id = ?', [$order->id()]));
    }

    public function testAReturnRequestIsRefusedWithAnExplanationWhenTheOrderIsNotReturnable(): void
    {
        $customer = $this->createCustomer('tooearly@example.com');
        $order = $this->placedOrder($customer);

        $this->client->loginUser($customer, 'main');
        $this->client->request('GET', '/hesabim/siparisler/'.$order->orderNumber().'/iade');

        // The page explains rather than offering a form that is guaranteed to be refused.
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Sipariş henüz ödemeniz onaylanmadı');
        self::assertSelectorNotExists('form[name="customer_return_request"]');
    }

    public function testTheReturnFormOnlyOffersTheQuantityStillFreeToReturn(): void
    {
        $customer = $this->createCustomer('remaining@example.com');
        $order = $this->confirmedOrder($customer, quantity: 3);
        $this->openReturn($customer, $order, 2, 'İlk talep.');

        $this->client->loginUser($customer, 'main');
        $crawler = $this->client->request('GET', '/hesabim/siparisler/'.$order->orderNumber().'/iade');

        self::assertResponseIsSuccessful();
        $quantity = $crawler->filter('input[name="customer_return_request[line_0][quantity]"]')->attr('max');
        self::assertSame('1', $quantity);
    }

    public function testACustomerSeesTheirOwnReturnDetailWithItsAuditTrail(): void
    {
        $customer = $this->createCustomer('returndetail@example.com');
        $order = $this->confirmedOrder($customer);
        $return = $this->openReturn($customer, $order, 1, 'Bozuk geldi.');
        $return->approve('Depoya alındı.', new \DateTimeImmutable('2026-09-28 12:00:00'), 'admin@example.com');
        $this->entityManager->flush();

        $this->client->loginUser($customer, 'main');
        $crawler = $this->client->request('GET', '/hesabim/iadeler/'.$return->returnNumber());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', $return->returnNumber());
        self::assertSelectorTextContains('main', 'Onaylandı');
        self::assertSelectorTextContains('main', 'Filtre');
        self::assertSelectorExists('[data-testid="return-events"]');
    }

    public function testACustomerCannotOpenAnotherCustomersReturnByGuessingItsNumber(): void
    {
        $owner = $this->createCustomer('owner@example.com');
        $stranger = $this->createCustomer('stranger@example.com');
        $order = $this->confirmedOrder($owner);
        $return = $this->openReturn($owner, $order, 1, 'Bozuk.');

        $this->client->loginUser($stranger, 'main');
        $this->client->request('GET', '/hesabim/iadeler/'.$return->returnNumber());

        self::assertResponseStatusCodeSame(404);
    }

    public function testACustomerCanWithdrawAnOpenReturnButNotOneWhoseGoodsHaveArrived(): void
    {
        $customer = $this->createCustomer('withdraw@example.com');
        $order = $this->confirmedOrder($customer);
        $orderNumber = $order->orderNumber();
        $open = $this->openReturn($customer, $order, 1, 'Bir.');

        $this->client->loginUser($customer, 'main');
        $crawler = $this->client->request('GET', '/hesabim/iadeler/'.$open->returnNumber());
        $this->client->submit($crawler->selectButton('Talebi geri al')->form());

        self::assertResponseRedirects('/hesabim/iadeler');
        $this->entityManager->clear();
        self::assertSame(ReturnState::Withdrawn, $this->reload($open)->state());

        // An *approved* return is still withdrawable — the customer may change their mind before
        // posting the goods. Once the store has them, it is too late.
        $this->entityManager->clear();
        $approved = $this->openReturn($this->reloadCustomer($customer->id()), $this->reloadOrder($orderNumber), 1, 'İki.');
        $this->reload($approved)->approve('Kabul.', new \DateTimeImmutable('2026-09-28 12:00:00'), 'admin@example.com');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/hesabim/iadeler/'.$approved->returnNumber());
        self::assertSelectorExists('form[action*="geri-al"]');
        // Minted while the button was still on the page, so the later refusal is proved by the
        // domain guard alone rather than by a stale or absent token.
        $token = $this->tokenFor($crawler, $approved->returnNumber());

        $this->reload($approved)->markReceived(new \DateTimeImmutable('2026-09-28 13:00:00'), 'admin@example.com');
        $this->entityManager->flush();

        $this->client->request('GET', '/hesabim/iadeler/'.$approved->returnNumber());
        self::assertSelectorNotExists('form[action*="geri-al"]');

        // Hidden *and* guarded: a well-formed, correctly-tokenised replay is still refused.
        $this->client->request('POST', '/hesabim/iadeler/'.$approved->returnNumber().'/geri-al', ['_token' => $token]);
        self::assertResponseRedirects('/hesabim/iadeler');
        $this->entityManager->clear();
        self::assertSame(ReturnState::Received, $this->reload($approved)->state());
    }

    public function testWithdrawingAnotherCustomersReturnIsA404AndChangesNothing(): void
    {
        $owner = $this->createCustomer('owner@example.com');
        $stranger = $this->createCustomer('stranger@example.com');
        $order = $this->confirmedOrder($owner);
        $return = $this->openReturn($owner, $order, 1, 'Bozuk.');

        $this->client->loginUser($stranger, 'main');
        $this->client->request('POST', '/hesabim/iadeler/'.$return->returnNumber().'/geri-al', [
            '_token' => 'anything',
        ]);

        self::assertResponseStatusCodeSame(404);
        $this->entityManager->clear();
        self::assertSame(ReturnState::Requested, $this->reload($return)->state());
    }

    public function testTheAccountNavLinksToOrdersAndReturns(): void
    {
        $customer = $this->createCustomer('nav@example.com');
        $this->client->loginUser($customer, 'main');

        $crawler = $this->client->request('GET', '/hesabim');

        self::assertSelectorExists('a[href$="/hesabim/siparisler"]');
        self::assertSelectorExists('a[href$="/hesabim/iadeler"]');
        self::assertCount(2, $crawler->filter('.account-nav a[href$="/hesabim/siparisler"], .account-nav a[href$="/hesabim/iadeler"]'));
    }

    public function testTheFooterLinksToOrdersAndReturnsForASignedInCustomer(): void
    {
        $customer = $this->createCustomer('footer@example.com');
        $this->client->loginUser($customer, 'main');

        $crawler = $this->client->request('GET', '/katalog');

        self::assertSelectorExists('.site-footer a[href$="/hesabim/siparisler"]');
        self::assertSelectorExists('.site-footer a[href$="/hesabim/iadeler"]');
    }

    public function testTheReturnRequestIsNotCreatableForAnotherCustomersOrderEvenByPostingItsNumber(): void
    {
        $owner = $this->createCustomer('owner@example.com');
        $stranger = $this->createCustomer('stranger@example.com');
        $order = $this->confirmedOrder($owner);
        $itemId = $order->items()[0]->id();

        $this->client->loginUser($stranger, 'main');
        $crawler = $this->client->request('GET', '/hesabim/siparisler/'.$order->orderNumber().'/iade');

        // The form is never rendered for a foreign order, and a hand-posted submit is refused too.
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/hesabim/siparisler/'.$order->orderNumber().'/iade', [
            'customer_return_request' => [
                'customerReason' => 'Elle gönderiliyorum.',
                'lines' => [['quantity' => 1, 'reason' => 'Elle.']],
            ],
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_return_request WHERE order_id = ?', [$order->id()]));
        self::assertNotNull($itemId);
    }

    private function reload(ReturnRequest $return): ReturnRequest
    {
        $reloaded = $this->entityManager->getRepository(ReturnRequest::class)->findOneBy(['returnNumber' => $return->returnNumber()]);
        self::assertInstanceOf(ReturnRequest::class, $reloaded);

        return $reloaded;
    }

    private function reloadOrder(string $orderNumber): CustomerOrder
    {
        $order = $this->entityManager->getRepository(CustomerOrder::class)->findOneBy(['orderNumber' => $orderNumber]);
        self::assertInstanceOf(CustomerOrder::class, $order);

        return $order;
    }

    /** The CSRF token the page minted, so the hand-posted withdraw is a well-formed forgery. */
    private function tokenFor(\Symfony\Component\DomCrawler\Crawler $crawler, string $returnNumber): string
    {
        $token = $crawler->filter(sprintf('form[action*="%s/geri-al"] input[name="_token"]', $returnNumber))->attr('value');
        self::assertIsString($token);

        return $token;
    }

    private function reloadCustomer(?int $id): CustomerUser
    {
        $customer = $this->entityManager->find(CustomerUser::class, $id);
        self::assertInstanceOf(CustomerUser::class, $customer);

        return $customer;
    }

    private function openReturn(CustomerUser $customer, CustomerOrder $order, int $quantity, string $reason): ReturnRequest
    {
        $return = ReturnRequest::open($order, sprintf('RET-20260928-%s', strtoupper(bin2hex(random_bytes(6)))), new \DateTimeImmutable('2026-09-28 11:00:00'), $reason, $customer->getUserIdentifier());
        $return->addItemFromOrder($order->items()[0], $quantity, $reason);
        $this->entityManager->persist($return);
        $this->entityManager->flush();

        return $return;
    }

    /**
     * The idempotency key and the provider reference are both unique per payment attempt, and a
     * test that settles several orders in one test method needs distinct values for each of
     * them. Deriving both from the order number keeps the helper usable more than once while
     * leaving every existing single-order caller unaffected.
 */
private function settlePayment(CustomerOrder $order): void
    {
        $payment = Payment::start($order, 'paytr', $order->grandTotal(), new \DateTimeImmutable('2026-09-28 10:00:00'));
        $attempt = $payment->beginAttempt('k-'.$order->orderNumber());
        $payment->markSucceeded($attempt, 'paytr-'.$order->orderNumber(), $order->grandTotal(), new \DateTimeImmutable('2026-09-28 10:05:00'));
        $this->entityManager->persist($payment);
        $this->entityManager->flush();
    }

    private function shippedParcel(CustomerOrder $order, ShipmentState $state, ?string $tracking): Shipment
    {
        $shipment = Shipment::start($order, 'local_standard', 'Yerel standart teslimat', 'manual', new \DateTimeImmutable('2026-09-28 10:00:00'));
        $shipment->markReady(null, $tracking, new \DateTimeImmutable('2026-09-28 11:00:00'));
        if (ShipmentState::InTransit === $state) {
            $shipment->markInTransit(new \DateTimeImmutable('2026-09-28 12:00:00'));
        }
        $this->entityManager->persist($shipment);
        $this->entityManager->flush();

        return $shipment;
    }

    private function createCustomer(string $email): CustomerUser
    {
        $customer = new CustomerUser($email, 'Efe', 'Yılmaz');
        $customer->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, 'VeryStrong!123'));
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function confirmedOrder(CustomerUser $customer, ?string $number = null, ?\DateTimeImmutable $at = null, int $quantity = 3): CustomerOrder
    {
        $order = $this->placedOrder($customer, $number, $at, $quantity);
        $order->transitionTo(OrderState::Confirmed);
        $this->entityManager->flush();

        return $order;
    }

    private function placedOrder(CustomerUser $customer, ?string $number = null, ?\DateTimeImmutable $at = null, int $quantity = 3): CustomerOrder
    {
        $at ??= new \DateTimeImmutable('2026-09-28 09:00:00');
        $lineGross = 123_456;
        $order = new CustomerOrder(
            $number ?? sprintf('EOA-20260928-%s', strtoupper(bin2hex(random_bytes(6)))),
            $customer,
            Money::ofMinor($lineGross * $quantity, 'TRY'),
            Money::ofMinor(20_576 * $quantity, 'TRY'),
            Money::ofMinor(0, 'TRY'),
            Money::ofMinor($lineGross * $quantity, 'TRY'),
            'local_standard',
            'Yerel standart teslimat',
            'gateway_checkout',
            'Kredi kartı',
            $at,
        );
        $order->addItem(null, 'SKU-1', 'Filtre', $quantity, Money::ofMinor($lineGross, 'TRY'), 2000, Money::ofMinor(102_880 * $quantity, 'TRY'), Money::ofMinor(20_576 * $quantity, 'TRY'), Money::ofMinor($lineGross * $quantity, 'TRY'));
        $order->addAddress(OrderAddressRole::Shipping, 'Efe Yılmaz', '05320000000', 'Atatürk Caddesi 1', null, 'Çukurova', 'Adana', '01170', 'TR');
        $order->addAddress(OrderAddressRole::Billing, 'Efe Yılmaz', '05320000000', 'Gaziantep Caddesi 9', null, 'Şahinbey', 'Gaziantep', '27100', 'TR');
        $order->sealSnapshots();
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    private function clear(): void
    {
        foreach ([
            'commerce_return_event',
            'commerce_return_request_item',
            'commerce_return_request',
            'commerce_payment_event',
            'commerce_payment_refund',
            'commerce_payment_attempt',
            'commerce_payment',
            'commerce_shipment_event',
            'commerce_shipment',
            'commerce_order_status_change',
            'commerce_order_address',
            'commerce_order_item',
            'commerce_customer_order',
            'commerce_notification',
            'messenger_messages',
        ] as $table) {
            $this->connection->executeStatement('DELETE FROM '.$table);
        }
        $this->connection->executeStatement('DELETE FROM customer_address');
        $this->connection->executeStatement('DELETE FROM customer_password_reset_token');
        $this->connection->executeStatement('DELETE FROM customer_user');
        $this->connection->executeStatement("DELETE FROM catalog_product WHERE sku LIKE 'UI-ORDER-%'");
    }
}
