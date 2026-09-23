<?php

namespace App\Entity\Cms;

use App\Module\Cms\HomeSectionType;
use App\Module\Cms\SectionConfiguration;
use App\Repository\Cms\HomeSectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HomeSectionRepository::class)]
#[ORM\Table(name: 'cms_home_section')]
#[ORM\Index(name: 'idx_cms_home_order', columns: ['enabled', 'sort_order', 'id'])]
class HomeSection
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\Column(length: 32, enumType: HomeSectionType::class)]
    private HomeSectionType $type;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $subtitle = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $configuration;

    #[ORM\Column]
    private bool $enabled = false;

    #[ORM\Column]
    private int $sortOrder = 0;

    /** @param array<string, mixed> $configuration */
    public function __construct(HomeSectionType $type, string $title, array $configuration)
    {
        $this->type = $type;
        $this->update($title, null, $configuration);
    }

    public function id(): ?int { return $this->id; }
    public function type(): HomeSectionType { return $this->type; }
    public function title(): string { return $this->title; }
    public function subtitle(): ?string { return $this->subtitle; }
    /** @return array<string, mixed> */
    public function configuration(): array { return $this->configuration; }
    public function enabled(): bool { return $this->enabled; }
    public function sortOrder(): int { return $this->sortOrder; }

    /** @param array<string, mixed> $configuration */
    public function update(string $title, ?string $subtitle, array $configuration): void
    {
        $title = trim($title);
        if ('' === $title || mb_strlen($title) > 255 || (null !== $subtitle && mb_strlen($subtitle) > 500)) {
            throw new \InvalidArgumentException('Invalid section title or subtitle.');
        }
        $this->title = $title;
        $this->subtitle = '' === trim($subtitle ?? '') ? null : trim($subtitle);
        $this->configuration = SectionConfiguration::validate($this->type, $configuration);
    }

    public function setEnabled(bool $enabled): void { $this->enabled = $enabled; }
    public function setSortOrder(int $sortOrder): void
    {
        if ($sortOrder < 0) { throw new \InvalidArgumentException('Sort order must be nonnegative.'); }
        $this->sortOrder = $sortOrder;
    }
}
