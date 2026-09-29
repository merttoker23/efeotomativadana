<?php

declare(strict_types=1);

namespace App\Entity\Seo;

use App\Module\Seo\SeoOverrides;
use App\Repository\Seo\SeoOverrideRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A merchant's own corrections to one content type's search metadata.
 *
 * Keyed by content type plus identifier rather than embedded in each catalogue and CMS table,
 * so that adding SEO to a new content type is a row type here rather than three nullable
 * columns on five tables, and so that the admin form for it is the same form everywhere.
 *
 * Every field is nullable. That is the requirement, not a convenience: a merchant fixing one
 * awkward product title must not be shown a form that also demands a description and an index
 * flag, and a page with no row at all must still produce complete metadata from its own facts.
 */
#[ORM\Entity(repositoryClass: SeoOverrideRepository::class)]
#[ORM\Table(name: 'seo_override')]
#[ORM\UniqueConstraint(name: 'uniq_seo_override_resource', columns: ['resource_type', 'resource_id'])]
class SeoOverride
{
    /** Google's own guidance puts a title near 60 characters and a snippet near 160. */
    public const TITLE_LIMIT = 255;
    public const DESCRIPTION_LIMIT = 320;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: SeoResourceType::class)]
    private SeoResourceType $resourceType;

    #[ORM\Column]
    private int $resourceId;

    #[ORM\Column(length: self::TITLE_LIMIT, nullable: true)]
    private ?string $metaTitle = null;

    #[ORM\Column(length: self::DESCRIPTION_LIMIT, nullable: true)]
    private ?string $metaDescription = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $noIndex = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        SeoResourceType $resourceType,
        int $resourceId,
        ?string $metaTitle = null,
        ?string $metaDescription = null,
        bool $noIndex = false,
    ) {
        if ($resourceId < 1) {
            throw new \InvalidArgumentException('An SEO override needs a real resource identifier.');
        }
        $this->resourceType = $resourceType;
        $this->resourceId = $resourceId;
        $this->retitle($metaTitle);
        $this->redescribe($metaDescription);
        $this->noIndex = $noIndex;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function for(SeoResourceType $resourceType, int $resourceId): self
    {
        return new self($resourceType, $resourceId);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function resourceType(): SeoResourceType
    {
        return $this->resourceType;
    }

    public function resourceId(): int
    {
        return $this->resourceId;
    }

    public function title(): ?string
    {
        return $this->metaTitle;
    }

    public function description(): ?string
    {
        return $this->metaDescription;
    }

    public function noIndex(): bool
    {
        return $this->noIndex;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function overrides(): SeoOverrides
    {
        return new SeoOverrides(
            title: $this->metaTitle,
            description: $this->metaDescription,
            noIndex: $this->noIndex ? true : null,
        );
    }

    /**
     * Refuses an over-long value instead of cutting it.
     *
     * A title is a decision, and a title that quietly loses its last words reads as a typo
     * rather than as a limit. The form states the limit; the aggregate refuses to guess.
     */
    public function retitle(?string $title): void
    {
        $this->metaTitle = $this->clean($title, self::TITLE_LIMIT, 'SEO title');
        $this->touch();
    }

    public function redescribe(?string $description): void
    {
        $this->metaDescription = $this->clean($description, self::DESCRIPTION_LIMIT, 'SEO description');
        $this->touch();
    }

    public function hideFromIndex(bool $noIndex): void
    {
        $this->noIndex = $noIndex;
        $this->touch();
    }

    private function clean(?string $value, int $limit, string $field): ?string
    {
        $value = trim((string) $value);
        if ('' === $value) {
            return null;
        }
        if (mb_strlen($value) > $limit) {
            throw new \InvalidArgumentException(sprintf('%s must not exceed %d characters.', $field, $limit));
        }

        return $value;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
