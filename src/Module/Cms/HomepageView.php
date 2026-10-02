<?php

declare(strict_types=1);

namespace App\Module\Cms;

/**
 * The homepage as the theme composes it, not as a flat list of modules.
 *
 * The theme does not stack every block full width. It places the category panel, the hero slider
 * and the top-sellers column side by side in one band, and it pairs the testimonial panel with
 * the blog feed in another. Rendering the same eleven modules as eleven independent full-width
 * sections would keep the data and throw away the page, so the renderer resolves those groups
 * here and the template only has to lay them out.
 *
 * A slot is null when the administrator has not filled that particular module, and the template
 * collapses the surrounding column rather than rendering an empty frame.
 */
final readonly class HomepageView
{
    /**
     * @param list<HomeSectionView> $blocks
     */
    public function __construct(
        public ?HomeSectionView $announcement = null,
        public ?HomeSectionView $categoryMenu = null,
        public ?HomeSectionView $heroSlider = null,
        public ?HomeSectionView $topSellers = null,
        public ?HomeSectionView $testimonials = null,
        public ?HomeSectionView $blogFeed = null,
        public array $blocks = [],
    ) {}

    /** The theme's upper band is only worth rendering when at least one of its three columns is. */
    public function hasTopRegion(): bool
    {
        return null !== $this->categoryMenu || null !== $this->heroSlider || null !== $this->topSellers;
    }

    public function hasBottomRegion(): bool
    {
        return null !== $this->testimonials || null !== $this->blogFeed;
    }

    public function isEmpty(): bool
    {
        return !$this->hasTopRegion() && !$this->hasBottomRegion() && [] === $this->blocks;
    }
}
