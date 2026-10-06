<?php

declare(strict_types=1);

namespace App\Entity\Cms;

use App\Repository\Cms\FaqItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FaqItemRepository::class)]
#[ORM\Table(name: 'cms_faq_item')]
#[ORM\Index(name: 'idx_cms_faq_storefront', columns: ['active', 'sort_order', 'id'])]
final class FaqItem
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $question;

    #[ORM\Column(type: Types::TEXT)]
    private string $answer;

    #[ORM\Column]
    private bool $active = false;

    #[ORM\Column]
    private int $sortOrder = 0;

    public function __construct(string $question, string $answer)
    {
        $this->update($question, $answer);
    }

    public function id(): ?int { return $this->id; }
    public function question(): string { return $this->question; }
    public function answer(): string { return $this->answer; }
    public function active(): bool { return $this->active; }
    public function sortOrder(): int { return $this->sortOrder; }

    public function update(string $question, string $answer): void
    {
        $question = trim($question);
        $answer = trim($answer);

        if ('' === $question || mb_strlen($question) > 255) {
            throw new \InvalidArgumentException('Soru boş bırakılamaz ve en fazla 255 karakter olabilir.');
        }
        if ('' === $answer || mb_strlen($answer) > 20_000) {
            throw new \InvalidArgumentException('Cevap boş bırakılamaz ve en fazla 20.000 karakter olabilir.');
        }

        $this->question = $question;
        $this->answer = $answer;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function setSortOrder(int $sortOrder): void
    {
        if ($sortOrder < 0 || $sortOrder > 1_000_000) {
            throw new \InvalidArgumentException('Sıra 0 ile 1.000.000 arasında olmalıdır.');
        }

        $this->sortOrder = $sortOrder;
    }
}
