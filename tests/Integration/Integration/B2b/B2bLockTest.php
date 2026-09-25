<?php

namespace App\Tests\Integration\Integration\B2b;

use App\Module\Integration\B2b\B2bSyncLock;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockInterface;

final class B2bLockTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testSecondInstanceCannotOverlapTheSameProviderButCanUseAnotherProvider(): void
    {
        $firstFactory = new B2bSyncLock($this->connection);
        $secondFactory = new B2bSyncLock($this->connection);
        $efe = $firstFactory->acquire('efe');
        $other = null;
        $efeAgain = null;
        $afterRelease = null;

        try {
            self::assertInstanceOf(LockInterface::class, $efe);
            self::assertNull($secondFactory->acquire('efe'));
            $other = $secondFactory->acquire('other');
            self::assertInstanceOf(LockInterface::class, $other);
            $efe->release();
            $efeAgain = $secondFactory->acquire('efe');
            self::assertInstanceOf(LockInterface::class, $efeAgain);
            $efeAgain->release();
            $efeAgain = null;
        } finally {
            if (null !== $efe) {
                $efe->release();
            }
            if (null !== $other) {
                $other->release();
            }
        }
    }
}
