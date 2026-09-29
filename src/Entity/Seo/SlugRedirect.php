<?php

declare(strict_types=1);

namespace App\Entity\Seo;

use App\Repository\Seo\SlugRedirectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A published resource's previous slug, kept so its old URL still reaches the store.
 *
 * A slug is an address other people already hold — in a search index, in a bookmark, in a
 * printed parts catalogue, in a link someone pasted into a forum. Renaming a published record
 * without a record of the old address turns every one of those into a 404, and the 404 is
 * invisible to whoever renamed it. Before this existed the application refused the rename
 * outright instead, which protected the URL but also meant a B2B feed could never correct a
 * product name without a human deciding it was time to break the link.
 *
 * `old_slug` is unique per content type rather than per row because one address can only mean
 * one thing at a time. When a later record takes over a slug that an earlier one retired, the
 * history is re-pointed at whoever holds it now; the alternative is two conflicting answers
 * to "where does /yeni/urun/x go", and only the most recent one is true.
 */
#[ORM\Entity(repositoryClass: SlugRedirectRepository::class)]
#[ORM\Table(name: 'seo_slug_redirect')]
#[ORM\UniqueConstraint(name: 'uniq_seo_slug_redirect_old_slug', columns: ['resource_type', 'old_slug'])]
#[ORM\Index(name: 'idx_seo_slug_redirect_resource', columns: ['resource_type', 'resource_id'])]
class SlugRedirect
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: SeoResourceType::class)]
    private SeoResourceType $resourceType;

    #[ORM\Column]
    private int $resourceId;

    #[ORM\Column(length: 255)]
    private string $oldSlug;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(SeoResourceType $resourceType, int $resourceId, string $oldSlug)
    {
        $this->resourceType = $resourceType;
        $this->resourceId = self::resourceIdentifier($resourceId);
        $this->oldSlug = self::slug($oldSlug);
        $this->createdAt = new \DateTimeImmutable();
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

    public function oldSlug(): string
    {
        return $this->oldSlug;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Re-point this address at whichever record now owns it.
     *
     * A null `resourceId` would mean "nobody", which is not a state this row can represent:
     * the whole point of the row is that somebody answers this address.
     */
    public function retire(SeoResourceType $resourceType, int $resourceId, string $oldSlug): void
    {
        $this->resourceType = $resourceType;
        $this->resourceId = self::resourceIdentifier($resourceId);
        $this->oldSlug = self::slug($oldSlug);
    }

    private static function resourceIdentifier(int $resourceId): int
    {
        if ($resourceId < 1) {
            throw new \InvalidArgumentException('A redirect needs a real resource identifier.');
        }

        return $resourceId;
    }

    /**
     * The same rule the catalog, CMS and B2B normalizer already apply to a slug. A value that
     * could not have been a public URL must never be stored as one, or the row would claim
     * to redirect an address that never existed.
     */
    private static function slug(string $slug): string
    {
        $slug = mb_strtolower(trim($slug));
        if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new \InvalidArgumentException('A retired slug must use lowercase ASCII letters, numbers and hyphens.');
        }

        return $slug;
    }
}
