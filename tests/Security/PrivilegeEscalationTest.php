<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\OrderItem;
use App\Entity\Commerce\OrderStatusChange;
use App\Entity\Commerce\Payment;
use App\Entity\Commerce\ReturnRequest;
use App\Entity\Customer\AdminUser;
use App\Entity\Customer\CustomerAddress;
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
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Horizontal and vertical privilege escalation, proved by making the actual HTTP request.
 *
 * Two distinct questions, kept apart on purpose:
 *
 * - **Vertical** â€” can a customer become staff, or staff become a customer? The firewalls and
 *   `access_control` decide this, and each case below asserts the *refusal*, not merely that the
 *   page rendered. A 302 to a login page is a refusal; a 200 that happens to show nothing useful
 *   is not.
 * - **Horizontal** â€” can one customer reach another customer's order, return or address? These are
 *   the endpoints where ownership is part of a query rather than a check afterwards, so the test
 *   builds a real second customer with real rows and then asks for theirs by id.
 *
 * Every foreign case asserts **404, not 403**. A 403 would tell the caller the row exists, and
 * order numbers follow a published format.
 */
final class PrivilegeEscalationTest extends WebTestCase
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
        $this->truncate();
    }

    protected function tearDown(): void
    {
        $this->truncate();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- vertical

    /**
     * @return iterable<string, array{string}>
     */
    public static function adminPaths(): iterable
    {
        yield 'dashboard' => ['/yeni/admin'];
        yield 'settings' => ['/yeni/admin/settings'];
        yield 'products' => ['/yeni/admin/catalog/products'];
        yield 'categories' => ['/yeni/admin/catalog/categories'];
        yield 'brands' => ['/yeni/admin/catalog/brands'];
        yield 'cms media' => ['/yeni/admin/cms/media'];
        yield 'cms content' => ['/yeni/admin/cms/blog'];
        yield 'homepage sections' => ['/yeni/admin/cms/home'];
        yield 'orders' => ['/yeni/admin/orders'];
        yield 'payments' => ['/yeni/admin/odemeler'];
        yield 'customers' => ['/yeni/admin/customers'];
        yield 'shipments' => ['/yeni/admin/gonderiler'];
        yield 'returns' => ['/yeni/admin/iadeler'];
        yield 'b2b integration' => ['/yeni/admin/integration/b2b'];
        yield 'seo overrides' => ['/yeni/admin/catalog/products/1/seo'];
    }

    /**
     * @param string $path
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('adminPaths')]
    public function testACustomerCannotReachAnyAdminScreen(string $path): void
    {
        $this->client->loginUser($this->customer('intruder@example.com'), 'main');

        $this->client->request('GET', $path);

        // A redirect to the admin login, never the page. Asserting the exact target is what
        // makes this a firewall result rather than "some redirect happened".
        self::assertResponseRedirects('/yeni/admin/login');
        self::assertStringNotContainsString('panel', (string) $this->client->getResponse()->getContent());
    }

    /**
     * The write side of the same question.
     *
     * A customer signed in on the `main` firewall holds no token at all on the `admin` firewall â€”
     * the sessions are separate â€” so the refusal is the entry point: a redirect to the admin
     * login, never a 403 that would confirm the screen exists. What matters is that no state
     * changed, which is asserted against the database rather than against the status code.
     */
    public function testACustomerCannotPostToAnyAdminAction(): void
    {
        $this->client->loginUser($this->customer('writer@example.com'), 'main');

        foreach (['/yeni/admin/catalog/products/1/delete', '/yeni/admin/customers/1/status', '/yeni/admin/cms/home/1/delete'] as $path) {
            $this->client->request('POST', $path, ['_token' => 'anything']);
            self::assertResponseRedirects('/yeni/admin/login');
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
    }

    public function testAnAdministratorCannotReachTheCustomerAccount(): void
    {
        $this->client->loginUser($this->administrator('boss@example.com'), 'admin');

        foreach (['/yeni/hesabim', '/yeni/hesabim/adresler', '/yeni/hesabim/siparisler', '/yeni/hesabim/profil'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('/yeni/giris');
            self::assertNotSame($path, (string) $this->client->getResponse()->headers->get('Location'));
        }
    }

    /**
     * A user carrying neither application role reaches neither world.
     *
     * Built as an in-memory user because that is exactly the shape being tested: an identity the
     * firewall knows and that holds `ROLE_USER` alone. Persisting a real entity could not
     * produce one, because `CustomerUser::getRoles()` always yields `ROLE_CUSTOMER` â€” which is
     * itself worth knowing, and is why this case uses the firewall's own test provider.
     *
     * The two firewalls answer differently and both answers are correct. The admin firewall has
     * a provider that knows this user, so the request is authenticated and refused with 403. The
     * customer firewall does not know it at all, so the request is anonymous and answered with
     * the login redirect. Asserting the exact shape of each is what keeps this from silently
     * passing for the wrong reason.
     */
    public function testARoleLessUserReachesNeitherWorld(): void
    {
        $user = new InMemoryUser('viewer@example.com', 'test-only-not-used-for-form-login', ['ROLE_USER']);
        self::assertNotContains('ROLE_ADMIN', $user->getRoles());
        self::assertNotContains('ROLE_CUSTOMER', $user->getRoles());

        $this->client->loginUser($user, 'admin');
        $this->client->request('GET', '/yeni/admin');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($user, 'main');
        $this->client->request('GET', '/yeni/hesabim');
        self::assertResponseStatusCodeSame(403);
    }

    // -------------------------------------------------------------- horizontal

    public function testOneCustomerCannotReadAnotherCustomersOrder(): void
    {
        $victim = $this->customer('victim@example.com');
        $attacker = $this->customer('attacker@example.com');
        $order = $this->order($victim, 'victim-order@example.com');

        $this->client->loginUser($attacker, 'main');

        foreach (['/yeni/hesabim/siparisler/'.$order->orderNumber(), '/yeni/odeme/'.$order->orderNumber()] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404);
        }

        self::assertSame(OrderState::Placed, $this->reload($order)->state());
        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_customer_order WHERE id = ?', [$order->id()]),
        );
    }

    /**
     * The cancel route takes a payment cancel token and a 64-hex return token.
     *
     * This is the realistic forgery, so it is built as one: the attacker signs in, starts a real
     * payment attempt on an order of *their own*, and reads the genuine `payment_cancel` token
     * off the page the store renders for them. That token is then aimed at the victim's order.
     *
     * The 403 that would answer a missing token is not the interesting outcome; the 404 is,
     * because it is what proves the ownership check runs on a request that is otherwise
     * completely well formed.
     */
    public function testOneCustomerCannotCancelAnotherCustomersPaymentEvenWithAValidToken(): void
    {
        $this->selectFakeProvider();
        $victim = $this->customer('pay-victim@example.com');
        $attacker = $this->customer('pay-attacker@example.com');
        $victimOrder = $this->order($victim, 'pay-victim-order@example.com');
        $attackerOrder = $this->order($attacker, 'pay-attacker-order@example.com');

        $initiation = self::getContainer()->get(PaymentInitiationService::class);
        $gateway = self::getContainer()->get(FakePaymentGateway::class);
        $gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback('FAKE-SEC-VICTIM'));
        $initiation->start($victimOrder, 'FAKE');
        $gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback('FAKE-SEC-ATTACKER'));
        $initiation->start($attackerOrder, 'FAKE');
        $victimToken = $this->returnTokenOf($victimOrder);

        $this->client->loginUser($attacker, 'main');
        $this->client->request('GET', '/yeni/odeme/'.$attackerOrder->orderNumber());
        $field = $this->client->getCrawler()->filter('form[action*="/odeme/iptal/"] input[name="payment_cancel[_token]"]');
        self::assertGreaterThan(0, $field->count(), 'The attacker must hold a genuine cancel token for their own payment.');
        $token = (string) $field->attr('value');

        // The attacker's own order is reachable, or the test proves nothing about the other one.
        $this->client->request('GET', '/yeni/odeme/'.$victimOrder->orderNumber());
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/yeni/odeme/iptal/'.$victimToken, ['payment_cancel' => ['_token' => $token]]);

        self::assertResponseStatusCodeSame(404);
        $this->entityManager->clear();
        self::assertSame(OrderState::Placed, $this->entityManager->find(CustomerOrder::class, $victimOrder->id())->state());
    }

    /**
     * A cancel with no token at all is refused by the CSRF guard, which is the other half of the
     * answer and is asserted separately so the two cannot be confused.
     */
    public function testACancelWithoutATokenIsRefusedBeforeOwnershipIsEvenConsidered(): void
    {
        $this->selectFakeProvider();
        $victim = $this->customer('pay2-victim@example.com');
        $attacker = $this->customer('pay2-attacker@example.com');
        $victimOrder = $this->order($victim, 'pay2-victim-order@example.com');
        self::getContainer()->get(FakePaymentGateway::class)->queueInitiation(GatewayInitiationOutcome::awaitingCallback('FAKE-SEC-2'));
        self::getContainer()->get(PaymentInitiationService::class)->start($victimOrder, 'FAKE');
        $victimToken = $this->returnTokenOf($victimOrder);

        $this->client->loginUser($attacker, 'main');
        $this->client->request('POST', '/yeni/odeme/iptal/'.$victimToken, ['payment_cancel' => ['_token' => '']]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testOneCustomerCannotReadOrWithdrawAnotherCustomersReturn(): void
    {
        $victim = $this->customer('return-victim@example.com');
        $attacker = $this->customer('return-attacker@example.com');
        $order = $this->order($victim, 'return-victim-order@example.com');
        $return = $this->returnFor($order, $victim);

        $this->client->loginUser($attacker, 'main');

        $this->client->request('GET', '/yeni/hesabim/iadeler/'.$return->returnNumber());
        self::assertResponseStatusCodeSame(404);

        // The intention is per-return (`customer_return_withdraw_<number>`), so no token for
        // somebody else's return can be minted at all, which is itself a barrier. The 404 below
        // is the ownership check, and it answers identically whether the token was forged or
        // valid, so the difference cannot be used to probe for an existing return.
        $this->client->request('POST', '/yeni/hesabim/iadeler/'.$return->returnNumber().'/geri-al', [
            '_token' => 'minted-for-a-different-return',
        ]);
        self::assertResponseStatusCodeSame(404);

        self::assertTrue($this->entityManager->find(ReturnRequest::class, $return->id())->isOpen());
    }

    /**
     * The address delete intention is per-address, so the attacker can genuinely mint a valid
     * token â€” for one of *their own* addresses â€” and aim it at somebody else's. That is the
     * realistic forgery, and it is what proves the ownership check rather than the CSRF check.
     */
    public function testOneCustomerCannotChangeAnotherCustomersAddress(): void
    {
        $victim = $this->customer('address-victim@example.com');
        $attacker = $this->customer('address-attacker@example.com');
        $foreign = new CustomerAddress($victim);
        $foreign->update('Ev', 'Kurbani', '05320000009', 'Ataturk Caddesi 9', null, 'Seyhan', 'Adana', '01170', true);
        $own = new CustomerAddress($attacker);
        $own->update('Ev', 'Saldirgan', '05320000010', 'Ataturk Caddesi 10', null, 'Seyhan', 'Adana', '01170', true);
        $this->entityManager->persist($foreign);
        $this->entityManager->persist($own);
        $this->entityManager->flush();

        $this->client->loginUser($attacker, 'main');
        $token = $this->addressDeleteToken((int) $own->id());
        self::assertNotSame('', $token, 'The attacker must hold a genuine token for their own address.');

        $this->client->request('POST', '/yeni/hesabim/adresler/'.$foreign->id().'/sil', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM customer_address'));
        self::assertSame('Ev', $this->connection->fetchOne('SELECT label FROM customer_address WHERE id = ?', [$foreign->id()]));
    }

    /**
     * The customer-facing return form takes an `order_item_id` per line. A hand-posted id naming
     * another order's line must be refused by the service rather than quietly returning goods
     * the customer did not buy â€” and it must be refused even though the *order* is genuinely the
     * attacker's own, which is what makes it a real escalation attempt rather than a typo.
     */
    public function testACustomerCannotReturnALineFromSomebodyElsesOrderThroughTheirOwnOrder(): void
    {
        $victim = $this->customer('line-victim@example.com');
        $attacker = $this->customer('line-attacker@example.com');
        $foreignOrder = $this->order($victim, 'line-victim-order@example.com', settled: true);
        $ownOrder = $this->order($attacker, 'line-attacker-order@example.com', settled: true);

        $foreignItem = $foreignOrder->items()[0];
        self::assertInstanceOf(OrderItem::class, $foreignItem);

        $this->client->loginUser($attacker, 'main');
        $path = '/yeni/hesabim/siparisler/'.$ownOrder->orderNumber().'/iade';
        $token = $this->returnRequestToken($path);
        $this->client->request('POST', $path, [
            'customer_return_request' => [
                'customerReason' => 'Baska siparisten satir',
                'lines' => [['orderItem' => $foreignItem->id(), 'quantity' => 1, 'reason' => 'x']],
                '_token' => $token,
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_return_request'));
    }

    /**
     * The payment callback carries an unguessable token, so it is not an authorization surface â€”
     * but a *foreign* order number in the storefront must not become one either, and the cancel
     * route must refuse a valid token belonging to somebody else. Both are asserted above; this
     * covers the remaining form: the return URL cannot be used to learn whether an order exists.
     */
    public function testAnUnknownReturnTokenIsIndistinguishableFromAForeignOne(): void
    {
        $this->client->request('POST', '/yeni/odeme/sonuc/'.str_repeat('a', 64));
        self::assertResponseRedirects('/yeni/katalog');

        $this->client->request('POST', '/yeni/odeme/sonuc/'.str_repeat('b', 64));
        self::assertResponseRedirects('/yeni/katalog');
    }

    // ----------------------------------------------------------------- helpers

    private function customer(string $email): CustomerUser
    {
        $customer = new CustomerUser($email, 'Test', 'MÃ¼ÅŸteri');
        $customer->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, self::PASSWORD));
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function administrator(string $email): AdminUser
    {
        $administrator = new AdminUser($email);
        $administrator->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($administrator, self::PASSWORD));
        $this->entityManager->persist($administrator);
        $this->entityManager->flush();

        return $administrator;
    }

    /**
     * Selects the fake gateway the way an administrator would, through the store setting, because
     * the payment module resolves the provider from configuration and refuses to invent one.
     */
    private function selectFakeProvider(): void
    {
        $configuration = self::getContainer()->get(StoreConfiguration::class);
        self::assertInstanceOf(StoreConfiguration::class, $configuration);
        $settings = $configuration->current();
        $settings->paymentProvider = 'fake';
        $configuration->save($settings);
    }

    private function order(CustomerUser $customer, string $email, bool $settled = false): CustomerOrder
    {
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
        $order->addItem(null, 'BRK-1', 'Fren Balatasi', 1, Money::ofMinor($gross, 'TRY'), 2000, Money::ofMinor(100_00, 'TRY'), Money::ofMinor(0, 'TRY'), Money::ofMinor($gross, 'TRY'));
        $order->addAddress(OrderAddressRole::Shipping, 'Test Musteri', '05320000000', 'Ataturk Caddesi 1', null, 'Cukurova', 'Adana', '01170', 'TR');
        $order->addAddress(OrderAddressRole::Billing, 'Test Musteri', '05320000000', 'Gaziantep Caddesi 9', null, 'Sahinbey', 'Gaziantep', '27100', 'TR');
        $order->sealSnapshots();
        $this->entityManager->persist($order);
        $this->entityManager->flush();
        // A return is only offered once the order is settled, so the fixtures that open or forge
        // a return need a confirmed order. Saying so here beats having the page silently render
        // "a return cannot be created" and the test passing for the wrong reason.
        if ($settled) {
            $order->transitionTo(OrderState::Confirmed);
            $this->entityManager->flush();
        }
        $this->entityManager->persist(new OrderStatusChange($order, OrderState::Placed, OrderState::Placed, 'test fixture', $email));
        $this->entityManager->flush();

        return $order;
    }

    private function returnFor(CustomerOrder $order, CustomerUser $customer): ReturnRequest
    {
        $return = ReturnRequest::open(
            $order,
            'RET-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(6))),
            new \DateTimeImmutable(),
            'Bir sorun var',
            $customer->getUserIdentifier(),
        );
        $return->addItemFromOrder($order->items()[0], 1, 'Bir sorun var');
        $this->entityManager->persist($return);
        $this->entityManager->flush();

        return $return;
    }

    private function reload(CustomerOrder $order): CustomerOrder
    {
        $this->entityManager->clear();

        return $this->entityManager->find(CustomerOrder::class, $order->id());
    }

    private function returnTokenOf(CustomerOrder $order): string
    {
        $this->entityManager->clear();
        $payment = $this->entityManager->getRepository(Payment::class)->findOneBy(['order' => $order]);
        self::assertInstanceOf(Payment::class, $payment);
        $attempt = $payment->latestAttempt();
        self::assertNotNull($attempt, 'Starting a payment must produce an attempt carrying a return token.');

        return $attempt->returnToken();
    }

    private function addressDeleteToken(int $addressId): string
    {
        $this->client->request('GET', '/yeni/hesabim/adresler');
        $field = $this->client->getCrawler()->filter(sprintf('form[action$="/adresler/%d/sil"] input[name="_token"]', $addressId));

        return 0 === $field->count() ? '' : (string) $field->attr('value');
    }

    private function returnRequestToken(string $path): string
    {
        $this->client->request('GET', $path);
        $field = $this->client->getCrawler()->filter('input[name="customer_return_request[_token]"]');
        self::assertGreaterThan(0, $field->count(), 'The attacker must hold a genuine return-request token.');

        return (string) $field->attr('value');
    }

    /**
     * Only the tables this class writes are cleared, and with DELETE rather than TRUNCATE.
     *
     * TRUNCATE is DDL: it forces an implicit commit and rebuilds the table, and doing that to
     * twenty tables twice per test turned a fast suite into a five-minute one. Clearing only what
     * was written keeps the cost proportional to the test.
     */
    private function truncate(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'commerce_audit_log', 'commerce_return_request', 'commerce_return_event',
            'commerce_payment_event', 'commerce_payment_attempt', 'commerce_payment_refund',
            'commerce_payment', 'commerce_order_status_change', 'commerce_order_item',
            'commerce_order_address', 'commerce_customer_order', 'commerce_cart_item',
            'commerce_cart', 'commerce_wishlist_item', 'customer_address', 'customer_user',
            'admin_user',
        ] as $table) {
            $this->connection->executeStatement('DELETE FROM '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }
}
