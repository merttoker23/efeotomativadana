<?php

namespace App\Entity\Cms;

use App\Repository\Cms\InformationPageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InformationPageRepository::class)]
#[ORM\Table(name: 'cms_information_page')]
#[ORM\UniqueConstraint(name: 'uniq_cms_page_slug', columns: ['slug'])]
class InformationPage
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;
    #[ORM\Column(length: 255)]
    private string $title;
    #[ORM\Column(length: 255)]
    private string $slug;
    #[ORM\Column(type: Types::TEXT)]
    private string $body;
    #[ORM\Column]
    private bool $published = false;

    public function __construct(string $title, string $slug, string $body)
    {
        $this->update($title, $slug, $body);
    }

    public function id(): ?int { return $this->id; }
    public function title(): string { return $this->title; }
    public function slug(): string { return $this->slug; }
    public function body(): string { return $this->body; }
    public function published(): bool { return $this->published; }
    public function setPublished(bool $published): void { $this->published = $published; }

    public function update(string $title, string $slug, string $body): void
    {
        CmsText::validate($title, $slug, $body);
        $this->title = trim($title);
        $this->slug = $slug;
        $this->body = trim($body);
    }
}
