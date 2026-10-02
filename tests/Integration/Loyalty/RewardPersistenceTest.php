<?php

declare(strict_types=1);

namespace App\Tests\Integration\Loyalty;

use App\Entity\Customer\AdminUser;
use App\Entity\Customer\CustomerUser;
use App\Entity\Loyalty\RewardTransaction;
use App\Module\Loyalty\RewardKind;
use App\Module\Loyalty\RewardService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RewardPersistenceTest extends KernelTestCase
{
    private Connection $db;
    private EntityManagerInterface $em;
    private RewardTransaction $entry;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        $this->db->beginTransaction();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $customer = new CustomerUser('immutable-reward-'.bin2hex(random_bytes(5)).'@example.com', 'Ledger', 'Customer');
        $customer->setPassword('test-only-hash');
        $admin = new AdminUser('immutable-admin-'.bin2hex(random_bytes(5)).'@example.com');
        $admin->setPassword('test-only-hash');
        $this->em->persist($customer);
        $this->em->persist($admin);
        $this->em->flush();
        $this->entry = self::getContainer()->get(RewardService::class)->adjust($customer, 1, 'Audit reason', $admin, 'entry');
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function testDatabaseEnforcesUniqueSourceEvenOutsideService(): void
    {
        $this->em->persist(new RewardTransaction($this->entry->customer(), null, RewardKind::ManualAdjustment, 2, $this->entry->sourceKey(), 'Different entry', $this->entry->actorEmail(), null, null, new \DateTimeImmutable()));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testPersistedLedgerCannotBeDeletedThroughDoctrine(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Reward ledger entries cannot be changed or deleted.');
        $this->em->remove($this->entry);
        $this->em->flush();
    }

    public function testPersistedLedgerCannotBeOverwrittenThroughDoctrine(): void
    {
        // Even accidental infrastructure mutation must not silently rewrite accounting history.
        (new \ReflectionProperty(RewardTransaction::class, 'points'))->setValue($this->entry, 99);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Reward ledger entries cannot be changed or deleted.');
        $this->em->flush();
    }
}
