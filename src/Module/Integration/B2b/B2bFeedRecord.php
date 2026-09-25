<?php

namespace App\Module\Integration\B2b;

final readonly class B2bFeedRecord
{
    private function __construct(
        private ?NormalizedCatalogFeedItem $item,
        private ?B2bItemError $error,
    ) {
        if ((null === $item) === (null === $error)) {
            throw new \InvalidArgumentException('A B2B feed record must contain exactly one item or error.');
        }
    }

    public static function success(NormalizedCatalogFeedItem $item): self { return new self($item, null); }
    public static function failure(B2bItemError $error): self { return new self(null, $error); }
    public function isSuccess(): bool { return null !== $this->item; }
    public function item(): ?NormalizedCatalogFeedItem { return $this->item; }
    public function error(): ?B2bItemError { return $this->error; }
}
