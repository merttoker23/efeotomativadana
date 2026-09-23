<?php

namespace App\Module\Cms;

enum HomeSectionType: string
{
    case AnnouncementBar = 'announcement_bar';
    case HeroSlider = 'hero_slider';
    case CategoryMenu = 'category_menu';
    case ProductCarousel = 'product_carousel';
    case BannerGrid = 'banner_grid';
    case Features = 'features';
    case BrandStrip = 'brand_strip';
    case ProductTabs = 'product_tabs';
    case Marquee = 'marquee';
    case Testimonials = 'testimonials';
    case BlogFeed = 'blog_feed';

    public function template(): string
    {
        return 'storefront/home/sections/_'.$this->value.'.html.twig';
    }
}
