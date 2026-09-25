<?php

namespace App\Module\Integration\B2b;

use App\Entity\Catalog\Product;
use App\Module\Inventory\InventoryManager;
use App\Module\Inventory\ProductInventoryRepositoryInterface;
use App\Repository\Integration\ExternalResourceMappingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockInterface;
use App\Module\Integration\B2b\Exception\B2bLockUnavailableException;

final readonly class B2bDailyReconciler
{
    public function __construct(
        private ExternalResourceMappingRepository $mappings,
        private EntityManagerInterface $entityManager,
        private ProductInventoryRepositoryInterface $inventories,
        private InventoryManager $inventory,
    ) {
    }

    public function reconcile(string $providerKey, int $runId, ?LockInterface $lock = null): B2bSyncCounters
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        $counters = B2bSyncCounters::empty();
        $afterId = 0;

        try {
            do {
                $ids = $this->mappings->findProductIdsNotSeenPage($providerKey, $runId, $afterId);
                if ([] === $ids) {
                    break;
                }
                foreach ($ids as $productId) {
                    $product = $this->entityManager->find(Product::class, $productId);
                    if (!$product instanceof Product) {
                        continue;
                    }
                    $this->inventories->findOneByProductForUpdate($product);
                    $this->inventory->upsert($product, 0, false);
                    $counters = $counters->recordStockUpdated();
                    if (null !== $lock) {
                        try {
                            $lock->refresh();
                        } catch (LockConflictedException $exception) {
                            throw new B2bLockUnavailableException('The B2B provider lock was lost during reconciliation.', 0, $exception);
                        }
                    }
                }
                $afterId = max($ids);
                $this->entityManager->clear();
            } while (true);
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            if ($this->entityManager->isOpen()) {
                $this->entityManager->clear();
            }

            throw $exception;
        }

        return $counters;
    }
}
