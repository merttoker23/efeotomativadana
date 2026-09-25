<?php

namespace App\Entity\Integration;

use App\Module\Integration\B2b\B2bResourceType;
use App\Repository\Integration\B2bSyncObservationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: B2bSyncObservationRepository::class)]
#[ORM\Table(name: 'integration_b2b_sync_observation')]
#[ORM\UniqueConstraint(name: 'uniq_integration_b2b_observation', columns: ['run_id', 'provider_key', 'resource_type', 'external_id'])]
#[ORM\Index(name: 'idx_integration_b2b_observation_position', columns: ['run_id', 'resource_type', 'stream_position'])]
#[ORM\Index(name: 'idx_integration_b2b_observation_sku', columns: ['run_id', 'resource_type', 'sku'])]
final class B2bSyncObservation
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

    #[ORM\Column(name: 'resource_type', length: 20, enumType: B2bResourceType::class)]
    private B2bResourceType $resourceType;

    #[ORM\Column(name: 'external_id', length: 191)]
    private string $externalId;

    #[ORM\Column(name: 'sku', length: 64, nullable: true)]
    private ?string $sku;

    #[ORM\Column(name: 'stream_position', nullable: true)]
    private ?int $streamPosition;

    #[ORM\Column(name: 'identity_sha256', length: 64, nullable: true)]
    private ?string $identitySha256;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        B2bSyncRun $run,
        string $externalId,
        B2bResourceType $resourceType,
        ?int $streamPosition,
        ?string $identitySha256,
        \DateTimeImmutable $createdAt,
        ?string $sku = null,
    ) {
        $externalId = trim($externalId);
        if ('' === $externalId || mb_strlen($externalId) > 191) {
            throw new \InvalidArgumentException('B2B observation external ID must contain between 1 and 191 characters.');
        }
        if (null !== $streamPosition && $streamPosition < 0) {
            throw new \InvalidArgumentException('B2B observation stream position cannot be negative.');
        }
        if (null !== $identitySha256 && 1 !== preg_match('/^[a-f0-9]{64}$/', $identitySha256)) {
            throw new \InvalidArgumentException('B2B observation identity must be a SHA-256 hex digest.');
        }
        if (null !== $sku) {
            $sku = mb_strtoupper(trim($sku));
            if ('' === $sku || mb_strlen($sku) > 64) {
                throw new \InvalidArgumentException('B2B observation SKU must contain between 1 and 64 characters.');
            }
        }

        $this->run = $run;
        $this->providerKey = $run->providerKey();
        $this->resourceType = $resourceType;
        $this->externalId = $externalId;
        $this->sku = $sku;
        $this->streamPosition = $streamPosition;
        $this->identitySha256 = $identitySha256;
        $this->createdAt = $createdAt;
    }

    public static function create(
        B2bSyncRun $run,
        string $externalId,
        B2bResourceType $resourceType,
        ?int $streamPosition,
        ?string $identitySha256,
        \DateTimeImmutable $createdAt,
        ?string $sku = null,
    ): self {
        return new self($run, $externalId, $resourceType, $streamPosition, $identitySha256, $createdAt, $sku);
    }

    public function id(): ?int { return $this->id; }
    public function run(): B2bSyncRun { return $this->run; }
    public function providerKey(): string { return $this->providerKey; }
    public function resourceType(): B2bResourceType { return $this->resourceType; }
    public function externalId(): string { return $this->externalId; }
    public function sku(): ?string { return $this->sku; }
    public function streamPosition(): ?int { return $this->streamPosition; }
    public function identitySha256(): ?string { return $this->identitySha256; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
}
