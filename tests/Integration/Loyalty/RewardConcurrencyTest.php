<?php

declare(strict_types=1);

namespace App\Tests\Integration\Loyalty;

use App\Entity\Customer\AdminUser;
use App\Entity\Customer\CustomerUser;
use App\Module\Loyalty\RewardService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/** Independent processes with real MySQL row locks and stale snapshots. */
final class RewardConcurrencyTest extends KernelTestCase
{
    private Connection $db;
    private CustomerUser $customer;
    private AdminUser $admin;
    /** @var list<CustomerUser> */
    private array $extraCustomers = [];
    /** @var list<Process> */
    private array $processes = [];
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->customer = new CustomerUser('concurrent-reward-'.bin2hex(random_bytes(6)).'@example.com', 'Concurrent', 'Customer');
        $this->customer->setPassword('test-hash');
        $this->admin = new AdminUser('concurrent-admin-'.bin2hex(random_bytes(6)).'@example.com');
        $this->admin->setPassword('test-hash');
        $em->persist($this->customer);
        $em->persist($this->admin);
        $em->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->db->executeStatement('DELETE FROM loyalty_reward_transaction WHERE customer_id = ?', [$this->customer->id()]);
        $this->db->executeStatement('DELETE FROM customer_user WHERE id = ?', [$this->customer->id()]);
        foreach ($this->extraCustomers as $customer) {
            $this->db->executeStatement('DELETE FROM loyalty_reward_transaction WHERE customer_id = ?', [$customer->id()]);
            $this->db->executeStatement('DELETE FROM customer_user WHERE id = ?', [$customer->id()]);
        }
        $this->db->executeStatement('DELETE FROM commerce_audit_log WHERE actor_email = ?', [$this->admin->getUserIdentifier()]);
        $this->db->executeStatement('DELETE FROM admin_user WHERE id = ?', [$this->admin->id()]);
        parent::tearDown();
    }

    public function testConcurrentReplayCreditsOnlyOnce(): void
    {
        $outputs = $this->runWriters(10, ['same-credit', 'same-credit']);
        self::assertSame(['applied', 'applied'], $outputs);
        self::assertSame(10, self::getContainer()->get(RewardService::class)->balance($this->customer));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loyalty_reward_transaction WHERE customer_id = ?', [$this->customer->id()]));
    }

    public function testConcurrentDebitsObserveCommittedBalanceEvenFromStaleSnapshots(): void
    {
        self::getContainer()->get(RewardService::class)->adjust($this->customer, 10, 'Initial credit', $this->admin, 'seed-credit');
        $outputs = $this->runWriters(-7, ['debit-a', 'debit-b']);
        sort($outputs);
        self::assertSame(['applied', 'refused'], $outputs);
        self::assertSame(3, self::getContainer()->get(RewardService::class)->balance($this->customer));
    }

    public function testUnrelatedCustomersCanCreateTheirFirstLedgerEntriesConcurrently(): void
    {
        $other = new CustomerUser('other-concurrent-'.bin2hex(random_bytes(6)).'@example.com', 'Other', 'Customer');
        $other->setPassword('test-only-hash');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($other);
        $em->flush();
        $this->extraCustomers[] = $other;
        $outputs = $this->runWriters(10, ['first-a', 'first-b'], [$this->customer, $other]);
        self::assertSame(['applied', 'applied'], $outputs);
        self::assertSame(10, self::getContainer()->get(RewardService::class)->balance($this->customer));
        self::assertSame(10, self::getContainer()->get(RewardService::class)->balance($other));
    }

    /**
     * @param list<string> $keys
     * @param list<CustomerUser> $customers
     * @return list<string>
     */
    private function runWriters(int $points, array $keys, array $customers = []): array
    {
        $release = sys_get_temp_dir().'/reward-release-'.bin2hex(random_bytes(8));
        $this->files[] = $release;
        foreach ($keys as $i => $key) {
            $ready = $release.'-'.$i;
            $this->files[] = $ready;
            $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/Loyalty/adjust.php', (string) ($customers[$i] ?? $this->customer)->id(), (string) $this->admin->id(), (string) $points, $key, 'unused', $ready, $release], dirname(__DIR__, 3));
            $process->setTimeout(90);
            $process->start();
            $this->processes[] = $process;
        }
        $deadline = microtime(true) + 60;
        while (!is_file($this->files[1]) || !is_file($this->files[2])) {
            foreach ($this->processes as $process) {
                if (!$process->isRunning()) {
                    self::fail('Concurrent worker exited before barrier: '.$process->getErrorOutput().$process->getOutput());
                }
            }
            if (microtime(true) > $deadline) {
                self::fail('Concurrent workers did not reach the barrier.');
            }
            usleep(20_000);
        }
        touch($release);
        $outputs = [];
        foreach ($this->processes as $process) {
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            $outputs[] = trim($process->getOutput());
        }
        return $outputs;
    }
}
