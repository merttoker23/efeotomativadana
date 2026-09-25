<?php

namespace App\Module\Integration\B2b;

final readonly class B2bSnapshot
{
    public string $path;
    public int $byteCount;
    public int $declaredCount;
    public string $sha256;
    public bool $reused;

    public function __construct(
        string $path,
        int $byteCount,
        int $declaredCount,
        string $sha256,
        bool $reused = false,
    ) {
        $path = trim($path);
        if ('' === $path || mb_strlen($path) > 500) {
            throw new \InvalidArgumentException('Snapshot path is invalid.');
        }
        if ($byteCount < 1 || $declaredCount < 0) {
            throw new \InvalidArgumentException('Snapshot counts are invalid.');
        }
        $sha256 = strtolower(trim($sha256));
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new \InvalidArgumentException('Snapshot SHA-256 is invalid.');
        }

        $this->path = $path;
        $this->byteCount = $byteCount;
        $this->declaredCount = $declaredCount;
        $this->sha256 = $sha256;
        $this->reused = $reused;
    }
}
