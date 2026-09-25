<?php

namespace App\Entity\Integration;

use App\Module\Integration\B2b\B2bErrorType;
use App\Repository\Integration\B2bSyncErrorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: B2bSyncErrorRepository::class)]
#[ORM\Table(name: 'integration_b2b_sync_error')]
#[ORM\Index(name: 'idx_integration_b2b_error_run', columns: ['run_id', 'id'])]
#[ORM\Index(name: 'idx_integration_b2b_error_type', columns: ['run_id', 'error_type', 'id'])]
class B2bSyncError
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: B2bSyncRun::class)]
    #[ORM\JoinColumn(name: 'run_id', nullable: false, onDelete: 'CASCADE')]
    private B2bSyncRun $run;

    #[ORM\Column(name: 'provider_key', length: 100)]
    private string $providerKey;

    #[ORM\Column(length: 20, enumType: \App\Module\Integration\B2b\B2bSyncMode::class)]
    private \App\Module\Integration\B2b\B2bSyncMode $mode;

    #[ORM\Column(name: 'external_id', length: 191, nullable: true)]
    private ?string $externalId;

    #[ORM\Column(name: 'error_type', length: 40, enumType: B2bErrorType::class)]
    private B2bErrorType $errorType;

    #[ORM\Column]
    private bool $retryable;

    #[ORM\Column(length: 1000)]
    private string $message;

    /** @var array<string, bool|int|string|null> */
    #[ORM\Column(type: Types::JSON)]
    private array $context;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, bool|int|string|null> $context */
    private function __construct(
        B2bSyncRun $run,
        ?string $externalId,
        B2bErrorType $errorType,
        bool $retryable,
        string $message,
        array $context,
        \DateTimeImmutable $createdAt,
    ) {
        $externalId = null === $externalId ? null : trim($externalId);
        if (null !== $externalId && ('' === $externalId || mb_strlen($externalId) > 191)) {
            throw new \InvalidArgumentException('External ID must contain between 1 and 191 characters.');
        }
        $message = trim($message);
        if ('' === $message) {
            throw new \InvalidArgumentException('B2B sync error message must not be empty.');
        }

        $this->run = $run;
        $this->providerKey = $run->providerKey();
        $this->mode = $run->mode();
        $this->externalId = $externalId;
        $this->errorType = $errorType;
        $this->retryable = $retryable;
        $this->message = mb_substr($message, 0, 1000);
        $this->context = $context;
        $this->createdAt = $createdAt;
    }

    /** @param array<string, bool|int|string|null> $context */
    public static function create(
        B2bSyncRun $run,
        ?string $externalId,
        B2bErrorType $errorType,
        bool $retryable,
        string $message,
        array $context,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self($run, $externalId, $errorType, $retryable, $message, $context, $createdAt);
    }

    public function id(): ?int { return $this->id; }
    public function run(): B2bSyncRun { return $this->run; }
    public function providerKey(): string { return $this->providerKey; }
    public function mode(): \App\Module\Integration\B2b\B2bSyncMode { return $this->mode; }
    public function externalId(): ?string { return $this->externalId; }
    public function errorType(): B2bErrorType { return $this->errorType; }
    public function retryable(): bool { return $this->retryable; }
    public function message(): string { return $this->message; }
    /** @return array<string, bool|int|string|null> */
    public function context(): array { return $this->context; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
}
