<?php

namespace App\Module\Integration\B2b;

final readonly class StoredProductImage
{
    public string $path;
    public string $absolutePath;

    public function __construct(string $path, string $absolutePath)
    {
        if (1 !== preg_match('~^/uploads/products/[a-z0-9]+\.(?:jpg|png|webp)$~', $path)) {
            throw new \InvalidArgumentException('Stored product image path is invalid.');
        }
        $absolutePath = trim($absolutePath);
        if ('' === $absolutePath || mb_strlen($absolutePath) > 500) {
            throw new \InvalidArgumentException('Stored product image absolute path is invalid.');
        }
        $this->path = $path;
        $this->absolutePath = $absolutePath;
    }
}
