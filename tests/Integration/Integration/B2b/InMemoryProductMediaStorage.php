<?php

namespace App\Tests\Integration\Integration\B2b;

use App\Module\Integration\B2b\ProductMediaStorageInterface;
use App\Module\Integration\B2b\StoredProductImage;

final class InMemoryProductMediaStorage implements ProductMediaStorageInterface
{
    /** @var list<string> */
    public array $stored = [];

    public function store(string $sourceUrl, string $altText): StoredProductImage
    {
        $this->stored[] = $sourceUrl;
        $name = hash('sha256', $sourceUrl).'.png';

        return new StoredProductImage('/uploads/products/'.$name, sys_get_temp_dir().'/'.$name);
    }

    public function remove(StoredProductImage $image): void
    {
    }
}
