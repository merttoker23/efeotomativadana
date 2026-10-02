<?php

declare(strict_types=1);

namespace App\Tests\Integration\Loyalty;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderAddressRole;
use App\Module\Payment\FakePaymentGateway;
use App\Module\Payment\Gateway\GatewayInitiationOutcome;
use App\Module\Payment\PaymentInitiationService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;
use App\Shared\Money\Money;

/** Exercise the payment/attempt lock order against an independent real callback transaction. */
final class PaymentRewardLockOrderingTest extends KernelTestCase
{
    private Connection $db;
    private ?CustomerOrder $order = null;
    private ?Process $callback = null;
    private string $readyFile;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        $this->db->executeStatement('SET SESSION innodb_lock_wait_timeout = 15');
        $this->readyFile = sys_get_temp_dir().'/payment-lock-order-'.bin2hex(random_bytes(8));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $customer = new CustomerUser('payment-lock-'.bin2hex(random_bytes(6)).'@example.com', 'Lock', 'Customer');
        $customer->setPassword('test-only-hash');
        $amount = Money::ofMinor(30_000, 'TRY');
        $zero = Money::ofMinor(0, 'TRY');
        $this->order = new CustomerOrder(
            'EOA-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(6))),
            $customer, $amount, $zero, $zero, $amount,
            'local_standard', 'Local delivery', 'gateway_checkout', 'Card', new \DateTimeImmutable(),
        );
        $this->order->addItem(null, 'LOCK-SKU', 'Lock ordering product', 1, $amount, 0, $amount, $zero, $amount);
        foreach ([OrderAddressRole::Shipping, OrderAddressRole::Billing] as $role) {
            $this->order->addAddress($role, 'Lock Customer', '05000000000', 'Street 1', null, 'Seyhan', 'Adana', null, 'TR');
        }
        $this->order->sealSnapshots();
        $em->persist($customer);
        $em->persist($this->order);
        $em->flush();
        self::getContainer()->get(FakePaymentGateway::class)->queueInitiation(
            GatewayInitiationOutcome::awaitingCallback('LOCK-'.bin2hex(random_bytes(6))),
        );
        self::getContainer()->get(PaymentInitiationService::class)->start($this->order, 'fake');
    }

    protected function tearDown(): void
    {
        // Release the payment holder first, then stop any failed/blocked child before deleting facts.
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        if (null !== $this->callback && $this->callback->isRunning()) {
            $this->callback->stop();
        }
        if (is_file($this->readyFile)) {
            unlink($this->readyFile);
        }
        if (null !== $this->order && null !== $this->order->id()) {
            $number = $this->order->orderNumber();
            $customer = $this->order->customer();
            $this->db->executeStatement('DELETE FROM messenger_messages WHERE body LIKE ?', ['%'.$number.'%']);
            $this->db->executeStatement('DELETE FROM commerce_notification WHERE subject_reference = ?', [$number]);
            $this->db->executeStatement('DELETE FROM commerce_audit_log WHERE (resource_type = ? AND resource_id = ?) OR (resource_type = ? AND resource_id = ?)', ['order', $number, 'reward_ledger', (string) $customer->id()]);
            $this->db->executeStatement('DELETE FROM loyalty_reward_transaction WHERE order_id = ?', [$this->order->id()]);
            // Order deletion cascades its payment, attempts/events, addresses/items and timeline.
            $this->db->executeStatement('DELETE FROM commerce_customer_order WHERE id = ?', [$this->order->id()]);
            $this->db->executeStatement('DELETE FROM customer_user WHERE id = ?', [$customer->id()]);
        }
        parent::tearDown();
    }

    public function testCaptureAndAnAdministrativePaymentHolderBothFinishWithoutDeadlock(): void
    {
        self::assertNotNull($this->order);
        $payment = self::getContainer()->get(PaymentInitiationService::class)->paymentFor($this->order);
        self::assertNotNull($payment);
        $attempt = $payment->latestAttempt();
        self::assertNotNull($attempt);

        // Match PaymentAdminManager::cancel(): payment is locked before its attempt is updated.
        $this->db->beginTransaction();
        $this->db->fetchOne('SELECT id FROM commerce_payment WHERE id = ? FOR UPDATE', [$payment->id()]);
        $this->callback = new Process([
            PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/Loyalty/callback.php',
            $attempt->returnToken(), $this->readyFile,
        ], dirname(__DIR__, 3));
        $this->callback->setTimeout(45);
        $this->callback->start();
        $connectionId = $this->awaitCallbackConnection();
        $this->awaitPaymentLockRequest($connectionId);

        $holderFailure = null;
        try {
            // Keep business state intact: this self-assignment requests the same attempt row
            // write lock as the administrator's flush, completing the cycle in the broken handler.
            $this->db->executeStatement('UPDATE commerce_payment_attempt SET updated_at = updated_at WHERE id = ?', [$attempt->id()]);
            $this->db->commit();
        } catch (\Throwable $failure) {
            $holderFailure = $failure;
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
        }
        $exitCode = $this->callback->wait();
        self::assertNull($holderFailure, 'The administrative payment holder failed: '.($holderFailure?->getMessage() ?? ''));
        self::assertSame(0, $exitCode, 'The real callback transaction failed: '.$this->callback->getErrorOutput());
        self::assertSame('applied', trim($this->callback->getOutput()));
        self::assertSame('succeeded', $this->db->fetchOne('SELECT state FROM commerce_payment WHERE id = ?', [$payment->id()]));
        self::assertSame(30_000, (int) $this->db->fetchOne('SELECT captured_minor_amount FROM commerce_payment WHERE id = ?', [$payment->id()]));
    }

    private function awaitCallbackConnection(): int
    {
        $deadline = microtime(true) + 25;
        while (!is_file($this->readyFile)) {
            $this->assertCallbackStillRunning();
            if (microtime(true) > $deadline) {
                self::fail('The callback process did not report its database connection.');
            }
            usleep(10_000);
        }
        return (int) file_get_contents($this->readyFile);
    }

    private function awaitPaymentLockRequest(int $connectionId): void
    {
        $deadline = microtime(true) + 12;
        $lastStatement = null;
        do {
            $this->assertCallbackStillRunning();
            // PROCESSLIST exposes this user's own connections without elevated DB privileges.
            // The holder owns this payment's exclusive lock, so this precise write/locking
            // read cannot finish. An event insert also requests a shared parent-payment
            // lock for its FK. Ordinary MVCC reads during callback setup do not match.
            $statement = $this->db->fetchOne('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID = ? AND COMMAND = ?', [$connectionId, 'Query']);
            if (is_string($statement)) {
                $lastStatement = $statement;
            }
            if (is_string($statement) && (
                1 === preg_match('/^UPDATE\s+`?commerce_payment`?\s/i', $statement)
                || 1 === preg_match('/^INSERT\s+INTO\s+`?commerce_payment_event`?\s/i', $statement)
                || (1 === preg_match('/\bFROM\s+`?commerce_payment`?\s/i', $statement) && str_contains(strtoupper($statement), 'FOR UPDATE'))
            )) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) <= $deadline);
        self::fail('The callback never requested the payment lock held by the administrator. Last statement: '.($lastStatement ?? 'none'));
    }

    private function assertCallbackStillRunning(): void
    {
        if (null === $this->callback || !$this->callback->isRunning()) {
            self::fail('The callback exited before reaching the contested lock: '.($this->callback?->getErrorOutput() ?? '').($this->callback?->getOutput() ?? ''));
        }
    }
}
