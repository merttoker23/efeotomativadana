<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\ReturnRequest;
use App\Entity\Customer\AdminUser;
use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderState;
use App\Module\Returns\ReturnLine;
use App\Module\Returns\ReturnService;
use App\Module\Returns\ReturnState;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * What an operator can do with a return, and what they cannot.
 *
 * The refusals matter more than the buttons here. An approval or a rejection is a statement the
 * store makes to a customer, and a stale screen must not be able to make it twice or contradict
 * one somebody else already made.
 */
final class ReturnAdminTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'returns-admin@example.com';

    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private ReturnService $returns;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $service = self::getContainer()->get(ReturnService::class);
        self::assertInstanceOf(ReturnService::class, $service);
        $this->returns = $service;
        $this->clear();
        $this->createAdmin();
    }

    protected function tearDown(): void
    {
        $this->clear();
        parent::tearDown();
    }

    public function testAnAdministratorSeesTheReturnListNewestFirstWithAFilter(): void
    {
        $admin = $this->admin();
        $older = $this->openReturn($admin, 'EOA-20260920-AAAA00000001', new \DateTimeImmutable('2026-09-20 10:00:00'));
        $newer = $this->openReturn($admin, 'EOA-20260928-BBBB00000002', new \DateTimeImmutable('2026-09-28 10:00:00'));

        $this->client->loginUser($this->adminUser(), 'admin');
        $crawler = $this->client->request('GET', '/admin/iadeler');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', $older->returnNumber());
        self::assertSelectorTextContains('main', $newer->returnNumber());
        self::assertCount(2, $crawler->filter('[data-testid="return-row"]'));
        // Newest first.
        self::assertStringContainsString($newer->returnNumber(), $crawler->filter('[data-testid="return-row"]')->first()->text());

        $this->client->request('GET', '/admin/iadeler', ['state' => 'approved']);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid="return-row"]'));
    }

    public function testAnUnknownStateFilterIsIgnoredRatherThanFatal(): void
    {
        $this->client->loginUser($this->adminUser(), 'admin');
        $this->client->request('GET', '/admin/iadeler', ['state' => 'not-a-state']);

        self::assertResponseIsSuccessful();
    }

    public function testAdministratorCanSearchReturnsByTheirOrderNumber(): void
    {
        $customer = $this->admin();
        $matched = $this->openReturn($customer, 'EOA-20260928-AAAA00000001');
        $other = $this->openReturn($customer, 'EOA-20260928-BBBB00000002');
        $this->client->loginUser($this->adminUser(), 'admin');
        $crawler = $this->client->request('GET', '/admin/iadeler', ['q' => ' eoa-20260928-aaaa00000001 ']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="return-row"]'));
        self::assertSelectorTextContains('main', $matched->returnNumber());
        self::assertSelectorTextNotContains('main', $other->returnNumber());
    }

    public function testTheAdminReturnDetailShowsTheRequestTheOrderAndTheTrail(): void
    {
        $admin = $this->admin();
        $return = $this->openReturn($admin, 'EOA-20260928-AAAA00000001');

        $this->client->loginUser($this->adminUser(), 'admin');
        $crawler = $this->client->request('GET', '/admin/iadeler/'.$return->returnNumber());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', $return->returnNumber());
        self::assertSelectorTextContains('main', $return->customerEmail());
        self::assertSelectorTextContains('main', 'EOA-20260928-AAAA00000001');
        self::assertSelectorTextContains('main', 'Filtre');
        self::assertSelectorExists('[data-testid="return-events"]');
    }

    public function testAnAdministratorCanApproveAReturnAndTheDecisionIsAudited(): void
    {
        $return = $this->openReturn($this->admin(), 'EOA-20260928-AAAA00000001');

        $this->client->loginUser($this->adminUser(), 'admin');
        $crawler = $this->client->request('GET', '/admin/iadeler/'.$return->returnNumber());
        $this->client->submit($crawler->selectButton('Onayla')->form([
            'return_approve[staffNote]' => 'Depoya alındı, kontrol edilecek.',
        ]));

        self::assertResponseRedirects('/admin/iadeler/'.$return->returnNumber());
        $this->entityManager->clear();
        $reloaded = $this->reload($return);
        self::assertSame(ReturnState::Approved, $reloaded->state());
        self::assertSame('Depoya alındı, kontrol edilecek.', $reloaded->staffNote());
        self::assertSame(self::ADMIN_EMAIL, $reloaded->events()[1]->actorEmail());
    }

    public function testARejectionRequiresAReasonTheCustomerCanBeShown(): void
    {
        $return = $this->openReturn($this->admin(), 'EOA-20260928-AAAA00000001');

        $this->client->loginUser($this->adminUser(), 'admin');
        $crawler = $this->client->request('GET', '/admin/iadeler/'.$return->returnNumber());
        $this->client->submit($crawler->selectButton('Reddet')->form([
            'return_reject[staffNote]' => '',
        ]));

        self::assertResponseStatusCodeSame(422);
        $this->entityManager->clear();
        self::assertSame(ReturnState::Requested, $this->reload($return)->state());
    }

    public function testAnAdministratorCanRejectWithAReason(): void
    {
        $return = $this->openReturn($this->admin(), 'EOA-20260928-AAAA00000001');

        $this->client->loginUser($this->adminUser(), 'admin');
        $crawler = $this->client->request('GET', '/admin/iadeler/'.$return->returnNumber());
        $this->client->submit($crawler->selectButton('Reddet')->form([
            'return_reject[staffNote]' => 'Kullanılmış ürün iadesi kabul edilmez.',
        ]));

        self::assertResponseRedirects('/admin/iadeler/'.$return->returnNumber());
        $this->entityManager->clear();
        self::assertSame(ReturnState::Rejected, $this->reload($return)->state());
    }

    public function testTheGoodsArrivalAndRefundRecordAreSeparateActions(): void
    {
        $return = $this->openReturn($this->admin(), 'EOA-20260928-AAAA00000001');
        $this->returns->approve($return, 'Kabul.', self::ADMIN_EMAIL);

        $this->client->loginUser($this->adminUser(), 'admin');
        $crawler = $this->client->request('GET', '/admin/iadeler/'.$return->returnNumber());
        $this->client->submit($crawler->selectButton('Ürünler geldi')->form(['return_received' => []]));

        self::assertResponseRedirects('/admin/iadeler/'.$return->returnNumber());
        $this->entityManager->clear();
        self::assertSame(ReturnState::Received, $this->reload($return)->state());

        $crawler = $this->client->request('GET', '/admin/iadeler/'.$return->returnNumber());
        $this->client->submit($crawler->selectButton('İade kaydını tamamla')->form([
            'return_refund[amountMinor]' => '45000',
            'return_refund[currency]' => 'TRY',
            'return_refund[refundReference]' => 'paytr_ref_9',
        ]));

        self::assertResponseRedirects('/admin/iadeler/'.$return->returnNumber());
        $this->entityManager->clear();
        $reloaded = $this->reload($return);
        self::assertSame(ReturnState::Refunded, $reloaded->state());
        self::assertSame('paytr_ref_9', $reloaded->refundReference());
    }

    /**
     * A recorded refund is a fact about money the payment module already moved. The returns module
     * must not be able to move money itself, so a refund cannot be recorded without a provider
     * reference to attribute it to.
     */
    public function testARefundCannotBeRecordedWithoutAProviderReference(): void
    {
        $return = $this->openReturn($this->admin(), 'EOA-20260928-AAAA00000001');
        $this->returns->approve($return, 'Kabul.', self::ADMIN_EMAIL);
        $this->returns->markReceived($return, self::ADMIN_EMAIL);

        $this->client->loginUser($this->adminUser(), 'admin');
        $crawler = $this->client->request('GET', '/admin/iadeler/'.$return->returnNumber());
        $this->client->submit($crawler->selectButton('İade kaydını tamamla')->form([
            'return_refund[amountMinor]' => '45000',
            'return_refund[currency]' => 'TRY',
            'return_refund[refundReference]' => '',
        ]));

        self::assertResponseStatusCodeSame(422);
        $this->entityManager->clear();
        self::assertSame(ReturnState::Received, $this->reload($return)->state());
    }

    public function testAClosedReturnOffersNoFurtherActions(): void
    {
        $return = $this->openReturn($this->admin(), 'EOA-20260928-AAAA00000001');
        $this->returns->reject($return, 'Kullanılmış ürün.', self::ADMIN_EMAIL);

        $this->client->loginUser($this->adminUser(), 'admin');
        $crawler = $this->client->request('GET', '/admin/iadeler/'.$return->returnNumber());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('button[value*="approve"]');
        self::assertSelectorNotExists('button[value*="reject"]');
    }

    public function testEachActionCarriesItsOwnCsrfTokenIdSoATokenCannotBeReplayedAcrossActions(): void
    {
        $return = $this->openReturn($this->admin(), 'EOA-20260928-AAAA00000001');

        $this->client->loginUser($this->adminUser(), 'admin');
        $crawler = $this->client->request('GET', '/admin/iadeler/'.$return->returnNumber());
        $approveToken = $crawler->filter('form[action$="onayla"] input[name="return_approve[_token]"]')->attr('value');
        self::assertIsString($approveToken);

        // The approval token presented to the rejection action is a cross-action forgery.
        $this->client->request('POST', '/admin/iadeler/'.$return->returnNumber().'/reddet', [
            'return_reject' => ['staffNote' => 'Kabul edilmez.', '_token' => $approveToken],
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->entityManager->clear();
        self::assertSame(ReturnState::Requested, $this->reload($return)->state());
    }

    public function testAnAnonymousVisitorIsSentToTheAdminLoginAndACustomerCannotReachTheAdminReturns(): void
    {
        $this->client->request('GET', '/admin/iadeler');
        self::assertResponseRedirects('/admin/login');

        // A customer session on the storefront firewall is sent to the admin login rather than
        // refused with a 403, which is what `access_control` does for every /admin path. That
        // is the safer answer: a 403 confirms the path exists, a redirect confirms nothing.
        $customer = $this->admin();
        $this->client->loginUser($customer, 'main');
        $this->client->request('GET', '/admin/iadeler');

        self::assertResponseRedirects('/admin/login');
        self::assertNotContains('ROLE_ADMIN', $customer->getRoles());
    }

    public function testAnUnknownReturnNumberIsA404(): void
    {
        $this->client->loginUser($this->adminUser(), 'admin');
        $this->client->request('GET', '/admin/iadeler/RET-20260928-FFFFFFFFFF');

        self::assertResponseStatusCodeSame(404);
    }

    public function testARefusedActionIsExplainedRatherThanCrashing(): void
    {
        $return = $this->openReturn($this->admin(), 'EOA-20260928-AAAA00000001');

        // The token is minted while the approval button is genuinely on the page, so the refusal
        // below is proved by the *domain* guard and not by a stale or absent token.
        $this->client->loginUser($this->adminUser(), 'admin');
        $crawler = $this->client->request('GET', '/admin/iadeler/'.$return->returnNumber());
        $token = $crawler->filter('form[action$="onayla"] input[name="return_approve[_token]"]')->attr('value');
        self::assertIsString($token);

        $this->returns->approve($return, 'Kabul.', self::ADMIN_EMAIL);

        $this->client->request('POST', '/admin/iadeler/'.$return->returnNumber().'/onayla', [
            'return_approve' => ['staffNote' => 'Tekrar onay.', '_token' => $token],
        ]);

        // Answered on the page with an explanation rather than a 500, because an operator pressing
        // the wrong button deserves to be told, not to see a stack trace.
        self::assertResponseStatusCodeSame(422);
        $this->entityManager->clear();
        self::assertSame(ReturnState::Approved, $this->reload($return)->state());
    }

    public function testAGetRequestCannotChangeAReturnState(): void
    {
        $return = $this->openReturn($this->admin(), 'EOA-20260928-AAAA00000001');

        $this->client->loginUser($this->adminUser(), 'admin');
        $this->client->request('GET', '/admin/iadeler/'.$return->returnNumber().'/onayla');

        self::assertResponseStatusCodeSame(405);
        $this->entityManager->clear();
        self::assertSame(ReturnState::Requested, $this->reload($return)->state());
    }

    private function reload(ReturnRequest $return): ReturnRequest
    {
        $reloaded = $this->entityManager->getRepository(ReturnRequest::class)->findOneBy(['returnNumber' => $return->returnNumber()]);
        self::assertInstanceOf(ReturnRequest::class, $reloaded);

        return $reloaded;
    }

    private function createAdmin(): void
    {
        $admin = new AdminUser(self::ADMIN_EMAIL);
        $admin->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($admin, 'VeryStrong!123'));
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
    }

    private function adminUser(): AdminUser
    {
        $admin = $this->entityManager->getRepository(AdminUser::class)->findOneBy(['email' => self::ADMIN_EMAIL]);
        self::assertInstanceOf(AdminUser::class, $admin);

        return $admin;
    }

    private function admin(): CustomerUser
    {
        $customer = new CustomerUser('shopper@example.com', 'Efe', 'Yılmaz');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function openReturn(CustomerUser $customer, string $orderNumber, ?\DateTimeImmutable $at = null): ReturnRequest
    {
        $at ??= new \DateTimeImmutable('2026-09-28 09:00:00');
        $order = new CustomerOrder(
            $orderNumber,
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

        return $this->returns->request($customer, $orderNumber, [new ReturnLine($order->items()[0], 1, 'Filtre kutusu ezilmiş geldi.')], 'Paket yırtık ulaştı.');
    }

    private function clear(): void
    {
        foreach ([
            'commerce_return_event',
            'commerce_return_request_item',
            'commerce_return_request',
            'commerce_order_status_change',
            'commerce_order_address',
            'commerce_order_item',
            'commerce_customer_order',
            'commerce_notification',
            'messenger_messages',
        ] as $table) {
            $this->connection->executeStatement('DELETE FROM '.$table);
        }
        $this->connection->executeStatement('DELETE FROM admin_user WHERE email = ?', [self::ADMIN_EMAIL]);
        $this->connection->executeStatement('DELETE FROM customer_address');
        $this->connection->executeStatement('DELETE FROM customer_password_reset_token');
        $this->connection->executeStatement('DELETE FROM customer_user');
    }
}
