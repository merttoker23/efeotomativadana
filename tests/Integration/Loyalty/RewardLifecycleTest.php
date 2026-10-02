<?php

declare(strict_types=1);

namespace App\Tests\Integration\Loyalty;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Customer\AdminUser;
use App\Entity\Customer\CustomerUser;
use App\Module\Loyalty\RewardService;
use App\Module\Order\AdminOrderManager;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderState;
use App\Module\Payment\FakePaymentGateway;
use App\Module\Payment\Gateway\GatewayInitiationOutcome;
use App\Module\Payment\Gateway\GatewayRefundOutcome;
use App\Module\Payment\Gateway\IncomingPaymentCallback;
use App\Module\Payment\PaymentCallbackHandler;
use App\Module\Payment\PaymentInitiationService;
use App\Module\Payment\PaymentRefundService;
use App\Module\Settings\StoreConfiguration;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RewardLifecycleTest extends KernelTestCase
{
    private Connection $db;
    private EntityManagerInterface $em;
    private RewardService $rewards;
    private StoreConfiguration $settings;
    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        $this->db->beginTransaction();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        $this->rewards = self::getContainer()->get(RewardService::class);
        $this->settings = self::getContainer()->get(StoreConfiguration::class);
        $this->gateway = self::getContainer()->get(FakePaymentGateway::class);
        $this->configure(true, 1);
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function testPaymentSuccessAutomaticallyEarnsOnceExcludingShippingAndReplayIsSafe(): void
    {
        $order = $this->order();
        $callback = $this->capture($order);
        $this->rewards->synchronize($order);
        $payment = self::getContainer()->get(PaymentInitiationService::class)->paymentFor($order);
        self::getContainer()->get(PaymentCallbackHandler::class)->handle($payment->latestAttempt()->returnToken(), $callback);
        self::assertSame(3, $this->rewards->balance($order->customer()));
        self::assertSame(1, $this->entries($order));
    }

    public function testPartialThenFullRefundUsesOriginalRateDespiteSettingsChanges(): void
    {
        $order = $this->order();
        $this->capture($order);
        $this->configure(false, 99);
        $this->refund($order, 9_999);
        self::assertSame(3, $this->rewards->balance($order->customer()));
        $this->refund($order, 1);
        self::assertSame(2, $this->rewards->balance($order->customer()));
        $this->refund($order, 20_099);
        self::assertSame(0, $this->rewards->balance($order->customer()));
        $this->refund($order, 5_000);
        $this->rewards->synchronize($order);
        self::assertSame(0, $this->rewards->balance($order->customer()));
        self::assertSame(3, $this->entries($order));
        self::assertSame(3, (int) $this->db->fetchOne("SELECT points FROM loyalty_reward_transaction WHERE order_id = ? AND kind = 'EARN'", [$order->id()]));
    }

    public function testCancellationAfterPartialRefundReversesOnlyRemainingEarn(): void
    {
        $order = $this->order();
        $this->capture($order);
        $this->refund($order, 10_000);
        $admin = self::getContainer()->get(AdminOrderManager::class);
        $admin->transition($order->orderNumber(), OrderState::Cancelled, 'Customer cancelled', $order->version(), 'staff@example.com');
        $this->rewards->synchronize($order);
        self::assertSame(0, $this->rewards->balance($order->customer()));
        self::assertSame(-3, (int) $this->db->fetchOne("SELECT SUM(points) FROM loyalty_reward_transaction WHERE order_id = ? AND kind = 'REVERSAL'", [$order->id()]));
        self::assertSame(3, $this->entries($order));
    }

    public function testDisabledLoyaltyStopsNewEarnAndKeepsOldHistoryIndependentOfB2b(): void
    {
        $first = $this->order();
        $this->capture($first);
        $this->configure(false, 1);
        $second = $this->order();
        $this->capture($second);
        self::assertSame(3, $this->rewards->balance($first->customer()));
        self::assertSame(0, $this->rewards->balance($second->customer()));
        self::assertSame(0, $this->entries($second));
        $this->configure(true, 1, true);
        $third = $this->order();
        $this->capture($third);
        self::assertSame(3, $this->rewards->balance($third->customer()));
    }

    public function testFailedPaymentAndUnpaidOrderCannotEarn(): void
    {
        $order = $this->order();
        $this->rewards->synchronize($order);
        $this->gateway->queueInitiation(GatewayInitiationOutcome::redirect('https://pay.test/form', 'FAIL-REWARD'));
        $service = self::getContainer()->get(PaymentInitiationService::class);
        $service->start($order, 'fake');
        self::getContainer()->get(PaymentCallbackHandler::class)->handle($service->paymentFor($order)->latestAttempt()->returnToken(), new IncomingPaymentCallback('ref=FAIL-REWARD&outcome=failed&amount=35099&currency=TRY', ['X-Fake-Signature' => 'valid'], []));
        self::assertSame(0, $this->entries($order));
    }

    public function testLateCaptureOnCancelledOrderNeverEarns(): void
    {
        $order = $this->order();
        self::getContainer()->get(AdminOrderManager::class)->transition($order->orderNumber(), OrderState::Cancelled, 'Cancel before capture', $order->version(), 'staff@example.com');
        $this->capture($order);
        self::assertSame(0, $this->entries($order));
    }

    public function testManualAdjustmentIsReasonedAttributableAndIdempotent(): void
    {
        $order = $this->order();
        $admin = $this->admin();
        $entry = $this->rewards->adjust($order->customer(), 10, 'Service recovery', $admin, 'same-request');
        $again = $this->rewards->adjust($order->customer(), 10, 'Service recovery', $admin, 'same-request');
        self::assertSame($entry->id(), $again->id());
        self::assertSame($admin->getUserIdentifier(), $entry->actorEmail());
        self::assertSame('Service recovery', $entry->reason());
        self::assertSame(10, $this->rewards->balance($order->customer()));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM commerce_audit_log WHERE action = 'loyalty.manually_adjusted'"));
    }

    public function testManualDebitCannotCreateNegativeBalance(): void
    {
        $order = $this->order();
        $admin = $this->admin();
        $this->rewards->adjust($order->customer(), 1, 'Goodwill', $admin, 'credit');
        $this->expectException(\DomainException::class);
        $this->rewards->adjust($order->customer(), -2, 'Correction', $admin, 'debit');
    }

    public function testReusedAdjustmentKeyWithDifferentPayloadIsRefused(): void
    {
        $order = $this->order();
        $admin = $this->admin();
        $this->rewards->adjust($order->customer(), 1, 'Goodwill', $admin, 'key');
        $this->expectException(\DomainException::class);
        $this->rewards->adjust($order->customer(), 2, 'Goodwill', $admin, 'key');
    }

    public function testRefundCanRecoverPreviouslyAdjustedEarnWithoutBlockingRealMoney(): void
    {
        $order = $this->order();
        $this->capture($order);
        $this->rewards->adjust($order->customer(), -3, 'Remove goodwill', $this->admin(), 'remove');
        $this->refund($order, 35_099);
        self::assertSame(-3, $this->rewards->balance($order->customer()));
        self::assertSame(0, $this->rewards->availableBalance($order->customer()));
    }

    private function configure(bool $enabled, int $rate, bool $b2b = false): void
    {
        $data = $this->settings->current();
        $data->loyaltyEnabled = $enabled;
        $data->loyaltyEarnPercentage = $rate;
        $data->b2bEnabled = $b2b;
        $data->b2bProvider = $b2b ? 'efe' : null;
        $this->settings->save($data);
    }

    private function order(): CustomerOrder
    {
        $customer = new CustomerUser('reward-'.bin2hex(random_bytes(6)).'@example.com', 'Reward', 'Customer');
        $customer->setPassword('test-password-hash');
        $money = Money::ofMinor(30_099, 'TRY');
        $zero = Money::ofMinor(0, 'TRY');
        $order = new CustomerOrder('EOA-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(6))), $customer, $money, $zero, Money::ofMinor(5_000, 'TRY'), Money::ofMinor(35_099, 'TRY'), 'local_standard', 'Local delivery', 'gateway_checkout', 'Card', new \DateTimeImmutable());
        $order->addItem(null, 'REWARD-SKU', 'Reward product', 1, $money, 0, $money, $zero, $money);
        foreach ([OrderAddressRole::Shipping, OrderAddressRole::Billing] as $role) {
            $order->addAddress($role, 'Reward Customer', '05000000000', 'Street 1', null, 'Seyhan', 'Adana', null, 'TR');
        }
        $order->sealSnapshots();
        $this->em->persist($customer);
        $this->em->persist($order);
        $this->em->flush();
        return $order;
    }

    private function capture(CustomerOrder $order): IncomingPaymentCallback
    {
        $reference = 'REWARD-'.bin2hex(random_bytes(6));
        $this->gateway->queueInitiation(GatewayInitiationOutcome::redirect('https://pay.test/form', $reference));
        $service = self::getContainer()->get(PaymentInitiationService::class);
        $service->start($order, 'fake');
        $callback = new IncomingPaymentCallback(http_build_query(['ref' => $reference, 'outcome' => 'succeeded', 'amount' => $order->grandTotal()->minorAmount(), 'currency' => 'TRY']), ['X-Fake-Signature' => 'valid'], []);
        self::getContainer()->get(PaymentCallbackHandler::class)->handle($service->paymentFor($order)->latestAttempt()->returnToken(), $callback);
        return $callback;
    }

    private function refund(CustomerOrder $order, int $amount): void
    {
        $this->gateway->queueRefund(GatewayRefundOutcome::completed('REWARD-REFUND-'.bin2hex(random_bytes(6))));
        self::getContainer()->get(PaymentRefundService::class)->refund($order, Money::ofMinor($amount, 'TRY'), 'Return refund', 'staff@example.com');
    }

    private function entries(CustomerOrder $order): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM loyalty_reward_transaction WHERE order_id = ?', [$order->id()]);
    }

    private function admin(): AdminUser
    {
        $admin = new AdminUser('reward-staff-'.bin2hex(random_bytes(4)).'@example.com');
        $admin->setPassword('test-only-hash');
        $this->em->persist($admin);
        $this->em->flush();
        return $admin;
    }
}
