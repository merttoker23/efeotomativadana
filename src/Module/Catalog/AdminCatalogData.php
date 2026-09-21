<?php

declare(strict_types=1);

namespace App\Module\Catalog;

use App\Entity\Catalog\Category;
use Symfony\Component\Validator\Constraints as Assert;

final class AdminCatalogData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $name = '';

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'The slug must use lowercase letters, numbers and hyphens.')]
    #[Assert\Length(max: 255)]
    public string $slug = '';

    public bool $published = false;
    public ?Category $parent = null;
}
