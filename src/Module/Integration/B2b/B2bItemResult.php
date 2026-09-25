<?php

namespace App\Module\Integration\B2b;

use App\Entity\Catalog\Product;

final readonly class B2bItemResult
{
    /**
     * @param list<B2bItemError> $deferredErrors
     */
    private function __construct(
        private ?Product $product,
        private B2bSyncCounters $counters,
        private ?B2bItemError $error,
        private array $deferredErrors,
    ) {
    }

    /** @param list<B2bItemError> $deferredErrors */
    public static function success(Product $product, B2bSyncCounters $counters, array $deferredErrors = []): self
    {
        return new self($product, $counters, null, $deferredErrors);
    }

    public static function failure(B2bItemError $error, ?B2bSyncCounters $counters = null): self
    {
        return new self(null, $counters ?? B2bSyncCounters::empty(), $error, []);
    }

    public function isSuccess(): bool { return null === $this->error; }
    public function product(): ?Product { return $this->product; }
    public function counters(): B2bSyncCounters { return $this->counters; }
    public function error(): ?B2bItemError { return $this->error; }
    /** @return list<B2bItemError> */
    public function deferredErrors(): array { return $this->deferredErrors; }
}
