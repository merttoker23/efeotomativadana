<?php

namespace App\Module\Integration\B2b;

interface ProductMediaStorageInterface
{
    public function store(string $sourceUrl, string $altText): StoredProductImage;

    public function remove(StoredProductImage $image): void;
}
