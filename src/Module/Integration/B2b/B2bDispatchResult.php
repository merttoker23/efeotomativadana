<?php

namespace App\Module\Integration\B2b;

final readonly class B2bDispatchResult
{
    private function __construct(
        private B2bDispatchStatus $status,
        private ?int $runId,
        private ?B2bSyncMode $mode,
        private ?string $reason,
    ) {
    }

    public static function disabled(): self { return new self(B2bDispatchStatus::Disabled, null, null, null); }
    public static function queued(int $runId, B2bSyncMode $mode): self { return new self(B2bDispatchStatus::Queued, $runId, $mode, null); }
    public static function coalesced(int $runId, B2bSyncMode $mode): self { return new self(B2bDispatchStatus::Coalesced, $runId, $mode, null); }
    public static function rejected(string $reason): self { return new self(B2bDispatchStatus::Rejected, null, null, $reason); }

    public function status(): B2bDispatchStatus { return $this->status; }
    public function runId(): ?int { return $this->runId; }
    public function mode(): ?B2bSyncMode { return $this->mode; }
    public function reason(): ?string { return $this->reason; }
}
