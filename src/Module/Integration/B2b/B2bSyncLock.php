<?php

namespace App\Module\Integration\B2b;

use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\DoctrineDbalStore;

final class B2bSyncLock
{
    /** The lease must outlast the full 600-second snapshot/validation budget. */
    private const TTL_SECONDS = 1200.0;

    private LockFactory $factory;

    public function __construct(Connection $connection)
    {
        $this->factory = new LockFactory(new DoctrineDbalStore($connection));
    }

    public function acquire(string $providerKey): ?LockInterface
    {
        $providerKey = mb_strtolower(trim($providerKey));
        if ('' === $providerKey || mb_strlen($providerKey) > 100) {
            throw new \InvalidArgumentException('B2B lock provider key is invalid.');
        }
        $lock = $this->factory->createLock('b2b-sync-'.$providerKey, self::TTL_SECONDS, false);
        try {
            return $lock->acquire() ? $lock : null;
        } catch (LockConflictedException) {
            return null;
        }
    }
}
