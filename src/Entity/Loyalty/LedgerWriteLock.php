<?php

declare(strict_types=1);

namespace App\Entity\Loyalty;

use Doctrine\ORM\Mapping as ORM;

/** A transaction-scoped writer gate; the migration creates its single row. */
#[ORM\Entity]
#[ORM\Table(name: 'loyalty_ledger_lock')]
final class LedgerWriteLock
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id = 1;

    public function id(): int { return $this->id; }
}
