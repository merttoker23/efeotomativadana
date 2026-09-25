<?php

namespace App\Module\Integration\B2b;

use Psr\Clock\ClockInterface;

final readonly class B2bSnapshotCleaner
{
    private string $rootDirectory;

    public function __construct(string $rootDirectory, private ClockInterface $clock)
    {
        $rootDirectory = rtrim($rootDirectory, "/\\");
        $resolvedRoot = realpath($rootDirectory);
        if (false === $resolvedRoot && !str_starts_with($rootDirectory, DIRECTORY_SEPARATOR)) {
            $parent = realpath(dirname($rootDirectory)) ?: getcwd();
            $resolvedRoot = $parent.DIRECTORY_SEPARATOR.basename($rootDirectory);
        }
        $this->rootDirectory = rtrim($resolvedRoot ?: $rootDirectory, "/\\");
        if ('' === $this->rootDirectory) {
            throw new \InvalidArgumentException('B2B snapshot root directory is required.');
        }
    }

    public function pruneExpired(string $directory, ?string $protectedPath = null): void
    {
        $directory = $this->insideRoot($directory);
        if (!is_dir($directory)) {
            return;
        }
        $cutoff = $this->clock->now()->getTimestamp() - (7 * 86400);
        foreach (glob($directory.'/*.part') ?: [] as $part) {
            @unlink($part);
        }
        $protected = null === $protectedPath ? null : realpath($protectedPath);
        foreach (glob($directory.'/*.json') ?: [] as $snapshot) {
            if (null !== $protected && realpath($snapshot) === $protected) {
                continue;
            }
            if (filemtime($snapshot) < $cutoff) {
                @unlink($snapshot);
            }
        }
    }

    public function remove(string $path): void
    {
        $path = $this->insideRoot($path);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function insideRoot(string $path): string
    {
        $normalized = $this->canonicalize($path);
        $prefix = $this->rootDirectory.DIRECTORY_SEPARATOR;
        if ($normalized !== $this->rootDirectory && !str_starts_with($normalized, $prefix)) {
            throw new \InvalidArgumentException('B2B snapshot path is outside the configured root.');
        }

        return $normalized;
    }

    private function canonicalize(string $path): string
    {
        $path = rtrim($path, "/\\");
        $resolved = realpath($path);
        if (false !== $resolved) {
            return $resolved;
        }
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return $this->canonicalize(dirname($path)).DIRECTORY_SEPARATOR.basename($path);
    }
}
