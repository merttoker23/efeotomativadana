<?php

namespace App\Module\Integration\B2b;

final readonly class B2bSnapshotRequest
{
    public string $providerKey;
    public int $runId;
    public B2bSyncMode $mode;
    public string $destinationDirectory;
    public ?string $existingSnapshotPath;
    public ?string $existingSnapshotHash;

    public function __construct(
        string $providerKey,
        int $runId,
        B2bSyncMode $mode,
        string $destinationDirectory,
        ?string $existingSnapshotPath = null,
        ?string $existingSnapshotHash = null,
    ) {
        $providerKey = mb_strtolower(trim($providerKey));
        if ('' === $providerKey || mb_strlen($providerKey) > 100) {
            throw new \InvalidArgumentException('Provider key is invalid.');
        }
        if ($runId < 1) {
            throw new \InvalidArgumentException('Run ID must be positive.');
        }
        $destinationDirectory = rtrim(trim($destinationDirectory), "/\\");
        if ('' === $destinationDirectory) {
            throw new \InvalidArgumentException('Snapshot destination directory is required.');
        }
        if (null !== $existingSnapshotHash) {
            $existingSnapshotHash = strtolower(trim($existingSnapshotHash));
            if (1 !== preg_match('/^[a-f0-9]{64}$/', $existingSnapshotHash)) {
                throw new \InvalidArgumentException('Existing snapshot hash must be a SHA-256 hex digest.');
            }
        }
        if (null !== $existingSnapshotPath) {
            $existingSnapshotPath = trim($existingSnapshotPath);
            if ('' === $existingSnapshotPath) {
                throw new \InvalidArgumentException('Existing snapshot path must not be blank.');
            }
        }

        $this->providerKey = $providerKey;
        $this->runId = $runId;
        $this->mode = $mode;
        $this->destinationDirectory = $destinationDirectory;
        $this->existingSnapshotPath = $existingSnapshotPath;
        $this->existingSnapshotHash = $existingSnapshotHash;
    }
}
