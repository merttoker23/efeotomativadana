<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Customer\AdminUser;
use App\Entity\Customer\CustomerAddress;
use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderState;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CustomerOrderOperationsTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $manager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->entityManager = $manager;
        $this->connection->beginTransaction();
        $this->loginAdmin();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testCustomerListIsPaginatedAndDetailNeverLeaksCredentialMaterial(): void
    {
        for ($index = 1; $index <= 22; ++$index) {
            $customer = $this->customer(sprintf('customer%02d@example.com', $index), sprintf('hash-secret-%02d', $index));
            if (1 === $index) {
                $address = new CustomerAddress($customer);
                $address->update('Workshop', 'Customer 01', '05000000001', 'Atatürk Cad. 1', null, 'Seyhan', 'Adana', null, true);
                $this->entityManager->persist($address);
            }
        }
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/yeni/admin/customers?q=%40example.com');
        self::assertResponseIsSuccessful();
        self::assertCount(20, $crawler->filter('[data-testid="customer-row"]'));
        self::assertSelectorExists('a[rel="next"]');

        $customer = $this->entityManager->getRepository(CustomerUser::class)->findOneBy(['email' => 'customer01@example.com']);
        self::assertInstanceOf(CustomerUser::class, $customer);
        $this->client->request('GET', '/yeni/admin/customers/'.$customer->id());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Workshop');
        self::assertStringNotContainsString('hash-secret-01', (string) $this->client->getResponse()->getContent());
    }

    public function testAdminCanDeactivateCustomerWithoutChangingPassword(): void
    {
        $customer = $this->customer('status@example.com', 'unchanged-password-hash');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/yeni/admin/customers/'.$customer->id());
        $this->client->submit($crawler->selectButton('Deactivate customer')->form());

        self::assertResponseRedirects();
        $row = $this->connection->fetchAssociative('SELECT active, password FROM customer_user WHERE id = ?', [$customer->id()]);
        self::assertIsArray($row);
        self::assertSame(0, (int) $row['active']);
        self::assertSame('unchanged-password-hash', $row['password']);
    }

    public function testRepeatedStaleDeactivationSubmissionCannotReactivateCustomer(): void
    {
        $customer = $this->customer('stale-status@example.com', 'unchanged-password-hash');
        $this->entityManager->flush();

        $firstForm = $this->client->request('GET', '/yeni/admin/customers/'.$customer->id())->selectButton('Deactivate customer')->form();
        $secondForm = $this->client->request('GET', '/yeni/admin/customers/'.$customer->id())->selectButton('Deactivate customer')->form();
        $this->client->submit($firstForm);
        self::assertResponseRedirects();
        $this->client->submit($secondForm);
        self::assertResponseRedirects();

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT active FROM customer_user WHERE id = ?', [$customer->id()]));
    }

    public function testDeactivatedCustomerSessionIsRejectedOnItsNextProtectedRequest(): void
    {
        $customer = $this->customer('session-status@example.com', 'unchanged-password-hash');
        $this->entityManager->flush();
        $this->client->loginUser($customer, 'main');
        $this->client->request('GET', '/yeni/hesabim');
        self::assertResponseIsSuccessful();

        $this->connection->executeStatement('UPDATE customer_user SET active = 0 WHERE id = ?', [$customer->id()]);
        $this->entityManager->clear();
        $this->client->request('GET', '/yeni/hesabim');

        self::assertResponseRedirects('/yeni/giris');
    }

    public function testValidOrderTransitionIsPersistedWithActorReasonAndVersion(): void
    {
        $order = $this->order('EOA-20260921-AAAABBBB0001');
        $this->entityManager->flush();
        $orderId = $order->id();

        $crawler = $this->client->request('GET', '/yeni/admin/orders/'.$order->orderNumber());
        $form = $crawler->selectButton('Update order status')->form([
            'order_transition[nextState]' => OrderState::Confirmed->value,
            'order_transition[reason]' => 'Payment checked manually.',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertSame('confirmed', $this->connection->fetchOne('SELECT state FROM commerce_customer_order WHERE id = ?', [$orderId]));
        $change = $this->connection->fetchAssociative('SELECT from_state, to_state, reason, actor_email FROM commerce_order_status_change WHERE order_id = ?', [$orderId]);
        self::assertIsArray($change);
        self::assertSame('placed', $change['from_state']);
        self::assertSame('confirmed', $change['to_state']);
        self::assertSame('Payment checked manually.', $change['reason']);
        self::assertSame('phase09-admin@example.com', $change['actor_email']);
    }

    public function testInvalidAndStaleOrderTransitionsAreRejectedWithoutHistory(): void
    {
        $order = $this->order('EOA-20260921-AAAABBBB0002');
        $this->entityManager->flush();
        $orderId = $order->id();
        $orderVersion = $order->version();

        $crawler = $this->client->request('GET', '/yeni/admin/orders/'.$order->orderNumber());
        $token = (string) $crawler->filter('input[name="order_transition[_token]"]')->attr('value');
        $this->client->request('POST', '/yeni/admin/orders/'.$order->orderNumber().'/status', [
            'order_transition' => [
                'nextState' => OrderState::Completed->value,
                'reason' => 'Skipping confirmation must fail.',
                'version' => (string) $orderVersion,
                '_token' => $token,
            ],
        ]);
        self::assertResponseStatusCodeSame(422);

        self::assertSame('placed', $this->connection->fetchOne('SELECT state FROM commerce_customer_order WHERE id = ?', [$orderId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_order_status_change WHERE order_id = ?', [$orderId]));

        $crawler = $this->client->request('GET', '/yeni/admin/orders/'.$order->orderNumber());
        $token = (string) $crawler->filter('input[name="order_transition[_token]"]')->attr('value');
        $this->client->request('POST', '/yeni/admin/orders/'.$order->orderNumber().'/status', [
            'order_transition' => [
                'nextState' => OrderState::Confirmed->value,
                'reason' => 'Stale browser tab.',
                'version' => '0',
                '_token' => $token,
            ],
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_order_status_change WHERE order_id = ?', [$orderId]));
    }

    public function testOrderDetailDisplaysImmutableShippingAndBillingAddresses(): void
    {
        $order = $this->order('EOA-20260921-AAAABBBB0003');
        $this->entityManager->flush();

        $this->client->request('GET', '/yeni/admin/orders/'.$order->orderNumber());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Shipping address');
        self::assertSelectorTextContains('main', 'Customer User');
        self::assertSelectorTextContains('main', 'Street 1');
        self::assertSelectorTextContains('main', 'Billing address');
        self::assertSelectorTextContains('main', 'Billing Contact');
        self::assertSelectorTextContains('main', 'Invoice Street 2');
    }

    public function testOrderHistoryUsesNewestIdWhenTimestampsMatch(): void
    {
        $order = $this->order('EOA-20260921-AAAABBBB0004');
        $this->entityManager->flush();
        $changedAt = '2026-09-21 12:00:00';
        $this->connection->insert('commerce_order_status_change', [
            'order_id' => $order->id(),
            'from_state' => OrderState::Placed->value,
            'to_state' => OrderState::Confirmed->value,
            'reason' => 'First transition.',
            'actor_email' => 'phase09-admin@example.com',
            'changed_at' => $changedAt,
        ]);
        $this->connection->insert('commerce_order_status_change', [
            'order_id' => $order->id(),
            'from_state' => OrderState::Confirmed->value,
            'to_state' => OrderState::Completed->value,
            'reason' => 'Second transition.',
            'actor_email' => 'phase09-admin@example.com',
            'changed_at' => $changedAt,
        ]);

        $crawler = $this->client->request('GET', '/yeni/admin/orders/'.$order->orderNumber());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Second transition.', $crawler->filter('article.subpanel')->eq(2)->text());
        self::assertStringContainsString('First transition.', $crawler->filter('article.subpanel')->eq(3)->text());
    }

    private function loginAdmin(): void
    {
        $admin = new AdminUser('phase09-admin@example.com');
        $admin->setPassword('test-password-hash');
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        $this->client->loginUser($admin, 'admin');
    }

    private function customer(string $email, string $passwordHash): CustomerUser
    {
        $customer = new CustomerUser($email, 'Customer', 'User');
        $customer->setPassword($passwordHash);
        $this->entityManager->persist($customer);

        return $customer;
    }

    private function order(string $number): CustomerOrder
    {
        $customer = $this->customer(mb_strtolower(substr($number, -4)).'@orders.example.com', 'order-password-hash');
        $zero = Money::ofMinor(0, 'TRY');
        $order = new CustomerOrder($number, $customer, $zero, $zero, $zero, $zero, 'local_standard', 'Local shipping', 'local_manual', 'Manual verification', new \DateTimeImmutable('2026-09-21 10:00:00 UTC'));
        $order->addItem(null, 'ORDER-SKU', 'Order Item', 1, $zero, 0, $zero, $zero, $zero);
        $order->addAddress(OrderAddressRole::Shipping, 'Customer User', '05000000000', 'Street 1', null, 'Seyhan', 'Adana', null, 'TR');
        $order->addAddress(OrderAddressRole::Billing, 'Billing Contact', '05000000002', 'Invoice Street 2', 'Suite 4', 'Çukurova', 'Adana', '01000', 'TR');
        $order->sealSnapshots();
        $this->entityManager->persist($order);

        return $order;
    }
}
