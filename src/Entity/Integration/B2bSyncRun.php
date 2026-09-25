<?php

namespace App\Entity\Integration;

use App\Module\Integration\B2b\B2bSyncCounters;
use App\Module\Integration\B2b\B2bSyncMode;
use App\Module\Integration\B2b\B2bSyncState;
use App\Repository\Integration\B2bSyncRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: B2bSyncRunRepository::class)]
#[ORM\Table(name: 'integration_b2b_sync_run')]
#[ORM\UniqueConstraint(name: 'uniq_integration_b2b_active_provider', columns: ['active_provider_key'])]
#[ORM\Index(name: 'idx_integration_b2b_provider_state', columns: ['provider_key', 'state', 'id'])]
#[ORM\Index(name: 'idx_integration_b2b_mode_state', columns: ['provider_key', 'mode', 'state', 'started_at'])]
#[ORM\Index(name: 'idx_integration_b2b_finished', columns: ['provider_key', 'mode', 'finished_at'])]
class B2bSyncRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\Column(name: 'provider_key', length: 100)]
    private string $providerKey;

    #[ORM\Column(length: 20, enumType: B2bSyncMode::class)]
    private B2bSyncMode $mode;

    #[ORM\Column(length: 20, enumType: B2bSyncState::class)]
    private B2bSyncState $state;

    #[ORM\Column(name: 'active_provider_key', length: 100, nullable: true)]
    private ?string $activeProviderKey;

    #[ORM\Column(name: 'declared_count', nullable: true)]
    private ?int $declaredCount;

    #[ORM\Column(name: 'snapshot_path', length: 500, nullable: true)]
    private ?string $snapshotPath;

    #[ORM\Column(name: 'snapshot_sha256', length: 64, nullable: true)]
    private ?string $snapshotSha256;

    #[ORM\Column]
    private int $checkpoint = 0;

    /** @var array<string, int> */
    #[ORM\Column(type: Types::JSON)]
    private array $counters;

    #[ORM\Column(name: 'latest_error', length: 2000, nullable: true)]
    private ?string $latestError = null;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'message_dispatched_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $messageDispatchedAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(string $providerKey, B2bSyncMode $mode, \DateTimeImmutable $now)
    {
        $providerKey = mb_strtolower(trim($providerKey));
        if ('' === $providerKey || mb_strlen($providerKey) > 100 || 1 !== preg_match('/^[a-z0-9][a-z0-9._-]*$/', $providerKey)) {
            throw new \InvalidArgumentException('Provider key contains invalid characters.');
        }

        $this->providerKey = $providerKey;
        $this->mode = $mode;
        $this->state = B2bSyncState::Queued;
        $this->activeProviderKey = $providerKey;
        $this->declaredCount = null;
        $this->snapshotPath = null;
        $this->snapshotSha256 = null;
        $this->counters = B2bSyncCounters::empty()->toArray();
        $this->createdAt = $this->updatedAt = $now;
    }

    public static function queue(string $providerKey, B2bSyncMode $mode, \DateTimeImmutable $now): self
    {
        return new self($providerKey, $mode, $now);
    }

    public function markRunning(\DateTimeImmutable $now): void
    {
        if (B2bSyncState::Running === $this->state) {
            return;
        }
        if (B2bSyncState::Queued !== $this->state) {
            throw new \DomainException('Only a queued B2B sync can start running.');
        }
        $this->state = B2bSyncState::Running;
        $this->latestError = null;
        $this->startedAt ??= $now;
        $this->updatedAt = $now;
    }

    public function markMessageDispatched(\DateTimeImmutable $now): void
    {
        if (in_array($this->state, [B2bSyncState::Completed, B2bSyncState::Failed, B2bSyncState::Cancelled], true)) {
            return;
        }
        $this->messageDispatchedAt ??= $now;
        $this->updatedAt = $now;
    }

    public function recordSnapshot(string $path, ?int $declaredCount, string $sha256, \DateTimeImmutable $now): void
    {
        $this->assertRunning();
        $path = trim($path);
        if ('' === $path || mb_strlen($path) > 500) {
            throw new \InvalidArgumentException('Snapshot path must contain between 1 and 500 characters.');
        }
        if (null !== $declaredCount && $declaredCount < 0) {
            throw new \InvalidArgumentException('Declared record count cannot be negative.');
        }
        $sha256 = strtolower(trim($sha256));
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new \InvalidArgumentException('Snapshot SHA-256 must be a 64-character hex digest.');
        }
        $this->snapshotPath = $path;
        $this->declaredCount = $declaredCount;
        $this->snapshotSha256 = $sha256;
        $this->updatedAt = $now;
    }

    public function recordBatch(B2bSyncCounters $batch, int $checkpoint, \DateTimeImmutable $now): void
    {
        $this->assertRunning();
        if ($checkpoint < $this->checkpoint) {
            throw new \InvalidArgumentException('B2B sync checkpoint cannot move backwards.');
        }
        $this->counters = B2bSyncCounters::fromArray($this->counters)->merge($batch)->toArray();
        $this->checkpoint = $checkpoint;
        $this->updatedAt = $now;
    }

    public function retry(string $error, \DateTimeImmutable $now): void
    {
        if (!in_array($this->state, [B2bSyncState::Queued, B2bSyncState::Running], true)) {
            throw new \DomainException('Only an active B2B sync can be retried.');
        }
        $this->state = B2bSyncState::Queued;
        $this->activeProviderKey = $this->providerKey;
        $this->latestError = self::errorText($error);
        $this->finishedAt = null;
        $this->updatedAt = $now;
    }

    public function complete(\DateTimeImmutable $now): void
    {
        $this->assertRunning();
        $this->state = B2bSyncState::Completed;
        $this->activeProviderKey = null;
        $this->latestError = null;
        $this->finishedAt = $now;
        $this->updatedAt = $now;
    }

    public function fail(string $error, \DateTimeImmutable $now): void
    {
        if (!in_array($this->state, [B2bSyncState::Queued, B2bSyncState::Running], true)) {
            throw new \DomainException('Only an active B2B sync can fail.');
        }
        $this->state = B2bSyncState::Failed;
        $this->activeProviderKey = null;
        $this->latestError = self::errorText($error);
        $this->finishedAt = $now;
        $this->updatedAt = $now;
    }

    public function cancel(\DateTimeImmutable $now): void
    {
        if (!in_array($this->state, [B2bSyncState::Queued, B2bSyncState::Running], true)) {
            throw new \DomainException('Only an active B2B sync can be cancelled.');
        }
        $this->state = B2bSyncState::Cancelled;
        $this->activeProviderKey = null;
        $this->finishedAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): ?int { return $this->id; }
    public function providerKey(): string { return $this->providerKey; }
    public function mode(): B2bSyncMode { return $this->mode; }
    public function state(): B2bSyncState { return $this->state; }
    public function activeProviderKey(): ?string { return $this->activeProviderKey; }
    public function declaredCount(): ?int { return $this->declaredCount; }
    public function snapshotPath(): ?string { return $this->snapshotPath; }
    public function snapshotSha256(): ?string { return $this->snapshotSha256; }
    public function checkpoint(): int { return $this->checkpoint; }
    public function counters(): B2bSyncCounters { return B2bSyncCounters::fromArray($this->counters); }
    public function latestError(): ?string { return $this->latestError; }
    public function version(): int { return $this->version; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function startedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function finishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function messageDispatchedAt(): ?\DateTimeImmutable { return $this->messageDispatchedAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    private function assertRunning(): void
    {
        if (B2bSyncState::Running !== $this->state) {
            throw new \DomainException('A B2B sync batch can only be recorded while running.');
        }
    }

    private static function errorText(string $error): string
    {
        $error = trim($error);
        if ('' === $error) {
            throw new \InvalidArgumentException('B2B sync error must not be empty.');
        }

        return mb_substr($error, 0, 2000);
    }
}
