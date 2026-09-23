<?php

namespace App\Module\Cms;

final readonly class HomeSectionView
{
    /** @param array<string, mixed> $data */
    public function __construct(public string $template, public string $title, public ?string $subtitle, public array $data) {}
}
