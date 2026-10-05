<?php

namespace App\Module\Cms;

use App\Module\Catalog\ProductFeedSource;

enum HomeSectionType: string
{
    case AnnouncementBar = 'announcement_bar';
    case PopupAd = 'popup_ad';
    case HeroSlider = 'hero_slider';
    case CategoryMenu = 'category_menu';
    case ProductCarousel = 'product_carousel';
    case BannerGrid = 'banner_grid';
    case Features = 'features';
    case SplitBuilder = 'split_builder';
    case BrandStrip = 'brand_strip';
    case ProductTabs = 'product_tabs';
    case Marquee = 'marquee';
    case Testimonials = 'testimonials';
    case BlogFeed = 'blog_feed';

    public function template(): string
    {
        return 'storefront/home/sections/_'.$this->value.'.html.twig';
    }

    /**
     * The admin form is generated from this description rather than written eleven times.
     *
     * `rowName` is the repeatable collection the section is built from, `rowFields` the ordered
     * fields of one row, `fields` the fixed fields beside them, and `selection` the kind of
     * catalogue picker the section needs. `null` in any of them means the section has none, which
     * is a fact about the section rather than a special case in the form.
     */
    public function rowName(): ?string
    {
        return match ($this) {
            self::HeroSlider => 'slides',
            self::BannerGrid => 'banners',
            self::Features => 'features',
            self::Testimonials => 'quotes',
            self::ProductTabs => 'tabs',
            self::AnnouncementBar, self::Marquee => 'items',
            default => null,
        };
    }

    /**
     * The ordered fields of one hero slide, in the order the theme stacks them.
     *
     * `tema/index.html`'s three slides are three different compositions of the same frame: a label,
     * a headline, and then either an offer pill ("Starting at $1,999", "Up to 50% OFF") or a pair
     * of calls to action. Both of those trailing parts are optional, which is why a slide is nine
     * fields rather than the four the frame could get by with.
     */
    private const array HERO_SLIDE_FIELDS = [
        'label', 'title',
        'priceLabel', 'priceValue',
        'primaryText', 'primaryLink',
        'secondaryText', 'secondaryLink',
        'image', 'mobileImage',
    ];

    /** @return list<string> */
    public function rowFields(): array
    {
        return match ($this) {
            self::HeroSlider => self::HERO_SLIDE_FIELDS,
            self::BannerGrid => ['title', 'image', 'link'],
            self::Features => ['title', 'description'],
            self::ProductTabs => ['title', 'source'],
            self::Testimonials => ['author', 'text'],
            self::AnnouncementBar, self::Marquee => ['text'],
            default => [],
        };
    }

    /** @return array<string, string> Fixed field name to field kind (textarea, link, number, text). */
    public function fields(): array
    {
        return match ($this) {
            self::PopupAd => ['description' => 'textarea', 'image' => 'image', 'mobileImage' => 'image', 'cta' => 'text', 'link' => 'link', 'delay' => 'delay', 'allowDismiss' => 'boolean'],
            self::BlogFeed => ['limit' => 'number'],
            // The promotional panel beside the split builder's product list: the theme's
            // `.big-promo`, which is a small label, a headline, a sentence and one call to action.
            self::SplitBuilder => ['label' => 'text', 'headline' => 'text', 'description' => 'textarea', 'cta' => 'text', 'link' => 'link'],
            default => [],
        };
    }

    /**
     * Whether a declared field holds a destination, and so needs the local-path control rather than
     * a plain text box.
     *
     * The hero's two calls to action carry their destination in their own field names, because a
     * slide has several buttons and one "link" would not say which one it belonged to.
     */
    public function isLinkField(string $field): bool
    {
        return \in_array($field, ['link', 'primaryLink', 'secondaryLink'], true);
    }

    /**
     * Whether a row field chooses where its products come from rather than naming them.
     *
     * Only a product tab has one. The field is a `<select>` rather than a text box because the set
     * of sources is closed: four of them, defined once on {@see ProductFeedSource} and rendered from
     * there, so a form can never offer a tab a source the storefront cannot answer.
     */
    public function isSourceField(string $field): bool
    {
        return self::ProductTabs === $this && 'source' === $field;
    }

    /**
     * The sources a product tab may be drawn from, in the order the storefront presents them.
     *
     * @return list<array{value: string, label: string}>
     */
    public function sourceChoices(): array
    {
        return array_map(
            static fn (ProductFeedSource $source): array => ['value' => $source->value, 'label' => $source->label()],
            ProductFeedSource::all(),
        );
    }

    /** 'products', 'categories', 'brands' or null when the section references no catalogue record. */
    public function selection(): ?string
    {
        return match ($this) {
            self::CategoryMenu => 'categories',
            self::ProductCarousel, self::SplitBuilder => 'products',
            self::BrandStrip => 'brands',
            self::ProductTabs => 'products',
            default => null,
        };
    }

    /**
     * Whether each row of a row-based section carries its own catalogue picker.
     *
     * Only the product tabs do: a tab is a titled product list, so its products belong to the tab
     * and not to the section.
     */
    public function selectionPerRow(): bool
    {
        return self::ProductTabs === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::AnnouncementBar => 'Duyuru Barı',
            self::PopupAd => 'Popup Reklam',
            self::HeroSlider => 'Hero Slider',
            self::CategoryMenu => 'Kategori Menüsü',
            self::ProductCarousel => 'Ürün Karuseli',
            self::BannerGrid => 'Banner Grid',
            self::Features => 'Özellikler',
            self::SplitBuilder => 'Split Builder',
            self::BrandStrip => 'Marka Şeridi',
            self::ProductTabs => 'Ürün Sekmeleri',
            self::Marquee => 'Kayan Yazı',
            self::Testimonials => 'Müşteri Yorumları',
            self::BlogFeed => 'Blog Akışı',
        };
    }

    /** Where the storefront places this section, so the admin form can say so in plain language. */
    public function placement(): string
    {
        return match ($this) {
            self::AnnouncementBar => 'Header’ın üstünde, duyurular kesintisiz kayan bir şeritte görünür.',
            self::PopupAd => 'Ana sayfada gecikmeli kampanya penceresi olarak görünür. Bölüm sırasındaki ilk aktif popup gösterilir.',
            self::HeroSlider => 'Üst bölgede, kategori menüsü ile çok satanlar arasındaki hero slider alanında görünür.',
            self::CategoryMenu => 'Üst bölgede, hero slider’ın solundaki kategori panelinde görünür.',
            self::ProductCarousel => 'Sıralamada ilk ürün karuseli üst bölgede “Çok satanlar” sütununda, sonrakiler tema ürün grid bölümünde görünür.',
            self::BannerGrid => 'Tema promo grid düzeninde, görselli tanıtım kartları olarak görünür.',
            self::Features => 'Tema özellikler şeridi düzeninde görünür.',
            self::SplitBuilder => 'Özellikler ile marka şeridi arasında, solda üç ürünlü liste ve sağda büyük kampanya paneli olarak görünür.',
            self::BrandStrip => 'Tema marka şeridi düzeninde, “Popüler Markalar” başlığıyla görünür.',
            self::ProductTabs => 'Tema ürün sekmeleri düzeninde, gerçek sekme davranışıyla görünür.',
            self::Marquee => 'Tema kayan yazı bandı düzeninde görünür.',
            self::Testimonials => 'Ana sayfanın altında, blog akışının solundaki yorum panelinde görünür.',
            self::BlogFeed => 'Ana sayfanın altında, yorumlar panelinin sağındaki blog kartlarında görünür.',
        };
    }

    /** @return array<string, string> */
    public function fieldLabels(): array
    {
        return [
            'title' => 'Başlık',
            'source' => 'Ürün kaynağı',
            'headline' => 'Kampanya başlığı',
            'label' => 'Üst etiket',
            'description' => 'Açıklama',
            'image' => self::PopupAd === $this ? 'Desktop görsel (isteğe bağlı)' : (self::HeroSlider === $this ? 'Desktop Banner' : 'Görsel'),
            'mobileImage' => self::PopupAd === $this ? 'Mobil görsel (isteğe bağlı)' : 'Mobil Banner',
            'delay' => 'Gösterim gecikmesi (saniye)',
            'allowDismiss' => '“Bir daha gösterme” seçeneğini sun',
            'link' => 'Bağlantı',
            'cta' => 'Buton metni',
            'text' => 'Metin',
            'author' => 'Yazar / müşteri adı',
            'limit' => 'Gösterilecek yazı sayısı',
            'priceLabel' => 'Fiyat etiketi (isteğe bağlı)',
            'priceValue' => 'Fiyat / kampanya değeri (isteğe bağlı)',
            'primaryText' => 'Birincil buton metni (isteğe bağlı)',
            'primaryLink' => 'Birincil buton bağlantısı (isteğe bağlı)',
            'secondaryText' => 'İkincil buton metni (isteğe bağlı)',
            'secondaryLink' => 'İkincil buton bağlantısı (isteğe bağlı)',
        ];
    }

    /**
     * A blank draft in the stored configuration's own shape.
     *
     * The draft is deliberately not a valid configuration: a brand new section has an empty
     * headline and no image, and refusing to render a form for it would leave an administrator
     * with nothing to fill in. {@see HomeSectionInput} is what turns a filled-in draft into a
     * configuration the domain will accept.
     *
     * @return array<string, mixed>
     */
    public function emptyDraft(): array
    {
        return match ($this) {
            self::PopupAd => ['description' => '', 'image' => '', 'mobileImage' => '', 'cta' => '', 'link' => '', 'delay' => 5, 'allowDismiss' => true],
            self::BlogFeed => ['limit' => 3],
            self::AnnouncementBar, self::Marquee => ['items' => [self::blankRow(['text'])]],
            self::HeroSlider => ['slides' => [self::blankRow(self::HERO_SLIDE_FIELDS)]],
            self::BannerGrid => ['banners' => [self::blankRow(['title', 'image', 'link'])]],
            self::Features => ['features' => [self::blankRow(['title', 'description'])]],
            self::SplitBuilder => ['label' => '', 'headline' => '', 'description' => '', 'cta' => '', 'link' => '', 'slugs' => []],
            self::Testimonials => ['quotes' => [self::blankRow(['author', 'text'])]],
            self::ProductTabs => ['tabs' => [['title' => '', 'source' => ProductFeedSource::legacyDefault()->value, 'slugs' => []]]],
            self::CategoryMenu, self::ProductCarousel, self::BrandStrip => ['slugs' => []],
        };
    }

    /**
     * @param list<string> $fields
     *
     * @return array<string, string>
     */
    private static function blankRow(array $fields): array
    {
        return array_fill_keys($fields, '');
    }
}
