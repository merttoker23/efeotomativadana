<?php

namespace App\Entity\Integration;

use App\Module\Integration\B2b\B2bResourceType;
use App\Repository\Integration\ExternalResourceMappingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExternalResourceMappingRepository::class)]
#[ORM\Table(name: 'integration_external_mapping')]
#[ORM\UniqueConstraint(name: 'uniq_integration_external_mapping', columns: ['provider_key', 'resource_type', 'external_id'])]
#[ORM\Index(name: 'idx_integration_mapping_local', columns: ['local_resource_type', 'local_resource_id'])]
#[ORM\Index(name: 'idx_integration_mapping_seen', columns: ['provider_key', 'resource_type', 'last_seen_run_id'])]
class ExternalResourceMapping
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $providerKey;

    #[ORM\Column(length: 20, enumType: B2bResourceType::class)]
    private B2bResourceType $resourceType;

    #[ORM\Column(length: 191)]
    private string $externalId;

    #[ORM\Column(length: 50)]
    private string $localResourceType;

    #[ORM\Column(length: 191)]
    private string $localResourceId;

    #[ORM\Column(name: 'first_seen_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(name: 'last_seen_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(name: 'last_seen_run_id', nullable: true)]
    private ?int $lastSeenRunId;

    #[ORM\Column(name: 'content_sha256', length: 64, nullable: true)]
    private ?string $contentSha256;

    #[ORM\Column(name: 'identity_version', type: Types::SMALLINT, options: ['default' => 1])]
    private int $identityVersion;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        string $providerKey,
        B2bResourceType $resourceType,
        string $externalId,
        string $localResourceType,
        int $localResourceId,
        \DateTimeImmutable $seenAt,
        ?int $runId,
        ?string $contentSha256 = null,
        int $identityVersion = 1,
    ) {
        $providerKey = self::key($providerKey, 'Provider key');
        $externalId = self::required($externalId, 'External ID', 191);
        $localResourceType = self::key($localResourceType, 'Local resource type');
        if ($localResourceId < 1) {
            throw new \InvalidArgumentException('Local resource ID must be a positive integer.');
        }
        if (null !== $runId && $runId < 1) {
            throw new \InvalidArgumentException('Last seen run ID must be a positive integer.');
        }
        if (null !== $contentSha256 && 1 !== preg_match('/^[a-f0-9]{64}$/', $contentSha256)) {
            throw new \InvalidArgumentException('Content fingerprint must be a SHA-256 hex digest.');
        }
        if ($identityVersion < 1) {
            throw new \InvalidArgumentException('Identity version must be positive.');
        }

        $this->providerKey = $providerKey;
        $this->resourceType = $resourceType;
        $this->externalId = $externalId;
        $this->localResourceType = $localResourceType;
        $this->localResourceId = (string) $localResourceId;
        $this->firstSeenAt = $this->lastSeenAt = $seenAt;
        $this->lastSeenRunId = $runId;
        $this->contentSha256 = $contentSha256;
        $this->identityVersion = $identityVersion;
        $this->updatedAt = $seenAt;
    }

    public static function product(
        string $providerKey,
        string $externalId,
        int $localProductId,
        ?int $runId,
        \DateTimeImmutable $seenAt,
        ?string $contentSha256 = null,
        int $identityVersion = 2,
    ): self {
        return new self($providerKey, B2bResourceType::Product, $externalId, 'product', $localProductId, $seenAt, $runId, $contentSha256, $identityVersion);
    }

    public static function create(
        string $providerKey,
        B2bResourceType $resourceType,
        string $externalId,
        string $localResourceType,
        int $localResourceId,
        \DateTimeImmutable $seenAt,
        ?int $runId,
        ?string $contentSha256 = null,
        int $identityVersion = 1,
    ): self {
        return new self($providerKey, $resourceType, $externalId, $localResourceType, $localResourceId, $seenAt, $runId, $contentSha256, $identityVersion);
    }

    public function seenIn(int $runId, \DateTimeImmutable $seenAt, ?string $contentSha256 = null, ?int $identityVersion = null): void
    {
        if ($runId < 1) {
            throw new \InvalidArgumentException('Last seen run ID must be a positive integer.');
        }
        if (null !== $contentSha256 && 1 !== preg_match('/^[a-f0-9]{64}$/', $contentSha256)) {
            throw new \InvalidArgumentException('Content fingerprint must be a SHA-256 hex digest.');
        }
        if (null !== $identityVersion && $identityVersion < 1) {
            throw new \InvalidArgumentException('Identity version must be positive.');
        }
        $this->lastSeenRunId = $runId;
        if (null !== $contentSha256) {
            $this->contentSha256 = $contentSha256;
        }
        if (null !== $identityVersion) {
            $this->identityVersion = $identityVersion;
        }
        $this->lastSeenAt = $seenAt;
        $this->updatedAt = $seenAt;
    }

    public function id(): ?int { return $this->id; }
    public function providerKey(): string { return $this->providerKey; }
    public function resourceType(): B2bResourceType { return $this->resourceType; }
    public function externalId(): string { return $this->externalId; }
    public function localResourceType(): string { return $this->localResourceType; }
    public function localResourceId(): string { return $this->localResourceId; }
    public function firstSeenAt(): \DateTimeImmutable { return $this->firstSeenAt; }
    public function lastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function lastSeenRunId(): ?int { return $this->lastSeenRunId; }
    public function contentSha256(): ?string { return $this->contentSha256; }
    public function identityVersion(): int { return $this->identityVersion; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    private static function key(string $value, string $field): string
    {
        $value = mb_strtolower(self::required($value, $field, 100));
        if (1 !== preg_match('/^[a-z0-9][a-z0-9._-]*$/', $value)) {
            throw new \InvalidArgumentException(sprintf('%s contains invalid characters.', $field));
        }

        return $value;
    }

    private static function required(string $value, string $field, int $maxLength): string
    {
        $value = trim($value);
        if ('' === $value || mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(sprintf('%s must contain between 1 and %d characters.', $field, $maxLength));
        }

        return $value;
    }
}
