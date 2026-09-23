<?php

namespace App\Entity\Cms;

use App\Repository\Cms\BlogPostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BlogPostRepository::class)]
#[ORM\Table(name: 'cms_blog_post')]
#[ORM\UniqueConstraint(name: 'uniq_cms_blog_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_cms_blog_published', columns: ['published', 'id'])]
class BlogPost
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;
    #[ORM\Column(length: 255)]
    private string $title;
    #[ORM\Column(length: 255)]
    private string $slug;
    #[ORM\Column(type: Types::TEXT)]
    private string $excerpt;
    #[ORM\Column(type: Types::TEXT)]
    private string $body;
    #[ORM\Column]
    private bool $published = false;

    public function __construct(string $title, string $slug, string $excerpt, string $body)
    {
        $this->update($title, $slug, $excerpt, $body);
    }

    public function id(): ?int { return $this->id; }
    public function title(): string { return $this->title; }
    public function slug(): string { return $this->slug; }
    public function excerpt(): string { return $this->excerpt; }
    public function body(): string { return $this->body; }
    public function published(): bool { return $this->published; }
    public function setPublished(bool $published): void { $this->published = $published; }

    public function update(string $title, string $slug, string $excerpt, string $body): void
    {
        CmsText::validate($title, $slug, $body);
        if ('' === trim($excerpt) || mb_strlen($excerpt) > 1000) { throw new \InvalidArgumentException('Invalid excerpt.'); }
        $this->title = trim($title);
        $this->slug = $slug;
        $this->excerpt = trim($excerpt);
        $this->body = trim($body);
    }
}
