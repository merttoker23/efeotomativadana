<?php

namespace App\Module\Cms;

use App\Module\Catalog\ProductFeedSource;

final class SectionConfiguration
{
    /**
     * The fields of a hero slide that may be left out, and what "left out" means for each.
     *
     * A slide is the theme's frame filled with a label, a headline, and then *either* an offer pill
     * *or* a pair of calls to action. Those trailing parts are therefore genuinely optional, and a
     * field that is optional may not be half-filled: a button with a label but no link (or the
     * reverse) would render a control that cannot be used, so the pair is checked together.
     */
    private const array OPTIONAL_SLIDE_FIELDS = [
        'label', 'title',
        'priceLabel', 'priceValue', 'primaryText', 'primaryLink', 'secondaryText', 'secondaryLink',
    ];

    /** The optional slide fields that are destinations rather than words. */
    private const array OPTIONAL_SLIDE_LINKS = ['primaryLink', 'secondaryLink'];

    /** A call to action is a label and a destination or it is nothing. */
    private const array SLIDE_FIELD_PAIRS = [
        ['primaryText', 'primaryLink'],
        ['secondaryText', 'secondaryLink'],
    ];

    /** @param array<string, mixed> $config
     *  @return array<string, mixed>
     */
    public static function validate(HomeSectionType $type, array $config): array
    {
        $schema = match ($type) {
            HomeSectionType::AnnouncementBar => array_key_exists('items', $config) ? ['items' => 'items'] : ['text' => 'text'],
            HomeSectionType::PopupAd => ['description' => 'optionalText', 'image' => 'optionalImage', 'mobileImage' => 'optionalImage', 'cta' => 'optionalText', 'link' => 'optionalLink', 'delay' => 'delay', 'allowDismiss' => 'boolean'],
            HomeSectionType::HeroSlider => ['slides' => 'slides'],
            HomeSectionType::CategoryMenu => ['slugs' => 'slugs'],
            HomeSectionType::ProductCarousel => ['slugs' => 'slugs'],
            HomeSectionType::BannerGrid => ['banners' => 'banners'],
            HomeSectionType::Features => ['features' => 'features'],
            HomeSectionType::SplitBuilder => ['label' => 'text', 'headline' => 'text', 'description' => 'text', 'cta' => 'text', 'link' => 'link', 'slugs' => 'slugs'],
            HomeSectionType::BrandStrip => ['slugs' => 'slugs'],
            HomeSectionType::ProductTabs => ['tabs' => 'tabs'],
            HomeSectionType::Marquee => ['items' => 'items'],
            HomeSectionType::Testimonials => ['quotes' => 'quotes'],
            HomeSectionType::BlogFeed => ['limit' => 'limit'],
        };
        $actualKeys = array_keys($config);
        $expectedKeys = array_keys($schema);
        sort($actualKeys);
        sort($expectedKeys);
        if ($actualKeys !== $expectedKeys) {
            throw new \InvalidArgumentException('Configuration must contain exactly: '.implode(', ', array_keys($schema)));
        }
        foreach ($schema as $key => $kind) {
            $value = $config[$key];
            if ('text' === $kind) {
                self::text($value);
            } elseif ('optionalText' === $kind) {
                self::optionalText($value);
            } elseif ('optionalImage' === $kind) {
                if ('' !== $value) { self::image($value); }
            } elseif ('optionalLink' === $kind) {
                self::optionalLink($value);
            } elseif ('delay' === $kind) {
                if (!is_int($value) || $value < 0 || $value > 300) {
                    throw new \InvalidArgumentException('Popup delay must be between 0 and 300 seconds.');
                }
            } elseif ('boolean' === $kind) {
                if (!is_bool($value)) { throw new \InvalidArgumentException('Popup preference must be boolean.'); }
            } elseif ('limit' === $kind) {
                if (!is_int($value) || $value < 1 || $value > 12) {
                    throw new \InvalidArgumentException('Blog limit must be between 1 and 12.');
                }
            } elseif ('link' === $kind) {
                self::link($value);
            } else {
                self::list($kind, $value);
            }
        }

        if (HomeSectionType::PopupAd === $type && ('' === trim($config['cta']) xor '' === trim($config['link']))) {
            throw new \InvalidArgumentException('A popup call to action needs both its text and its link.');
        }

        return $config;
    }

    private static function text(mixed $value): void
    {
        if (!is_string($value) || '' === trim($value) || mb_strlen($value) > 500) {
            throw new \InvalidArgumentException('Text must contain between 1 and 500 characters.');
        }
    }

    /**
     * A field the theme lets a slide leave out: absent, or a value within the same bounds the
     * required version has. An empty string is the only way to say "not this time" here, because
     * the key itself is part of the shape every slide shares.
     */
    private static function optionalText(mixed $value): void
    {
        if (!is_string($value) || mb_strlen($value) > 500) {
            throw new \InvalidArgumentException('Optional text must be a string of at most 500 characters.');
        }
    }

    private static function optionalLink(mixed $value): void
    {
        self::optionalText($value);
        if ('' !== trim($value)) {
            self::link($value);
        }
    }

    private static function slug(mixed $value): void
    {
        if (!is_string($value) || 1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) || strlen($value) > 255) {
            throw new \InvalidArgumentException('Invalid catalog slug.');
        }
    }

    private static function image(mixed $value): void
    {
        if (!is_string($value) || 1 !== preg_match('~^/uploads/cms/[a-f0-9]{32}\.(?:jpg|png|webp)$~', $value)) {
            throw new \InvalidArgumentException('Image must be an uploaded CMS image.');
        }
    }

    private static function link(mixed $value): void
    {
        if (!is_string($value) || 1 !== preg_match('~^/(?!/)[a-zA-Z0-9/_#?=&%-]*$~', $value) || strlen($value) > 500) {
            throw new \InvalidArgumentException('Links must be local paths.');
        }
    }

    private static function list(string $kind, mixed $value): void
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 20) {
            throw new \InvalidArgumentException('Configuration list must have at most 20 entries.');
        }
        if ([] === $value) {
            throw new \InvalidArgumentException($kind.' must not be empty.');
        }
        foreach ($value as $item) {
            if ('slugs' === $kind) {
                self::slug($item);
                continue;
            }
            if ('items' === $kind) {
                self::text($item);
                continue;
            }
            $fields = match ($kind) {
                'slides' => HomeSectionType::HeroSlider->rowFields(),
                'banners' => ['title', 'image', 'link'],
                'features' => ['title', 'description'],
                'tabs' => ['title', 'slugs'],
                'quotes' => ['author', 'text'],
                default => throw new \LogicException('Unknown configuration list.'),
            };
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Invalid '.$kind.' entry.');
            }
            // Older CMS JSON remains valid without a mobile banner; no data migration is needed.
            if ('slides' === $kind && !array_key_exists('mobileImage', $item)) {
                $item['mobileImage'] = '';
            }
            if ('tabs' === $kind) {
                self::tab($item);

                continue;
            }
            $actualFields = array_keys($item);
            sort($actualFields);
            $expectedFields = $fields;
            sort($expectedFields);
            if ($actualFields !== $expectedFields) { throw new \InvalidArgumentException('Invalid '.$kind.' entry.'); }
            foreach ($fields as $field) {
                if (\in_array($field, self::OPTIONAL_SLIDE_FIELDS, true)) {
                    \in_array($field, self::OPTIONAL_SLIDE_LINKS, true)
                        ? self::optionalLink($item[$field])
                        : self::optionalText($item[$field]);

                    continue;
                }
                match ($field) {
                    'image' => self::image($item[$field]),
                    'mobileImage' => '' === $item[$field] ? null : self::image($item[$field]),
                    'link' => self::link($item[$field]),
                    'slugs' => self::list('slugs', $item[$field]),
                    default => self::text($item[$field]),
                };
            }
            if ('slides' === $kind) {
                self::slidePairs($item);
            }
        }
    }

    /**
     * One product tab: a title, the source its products come from, and the products themselves when
     * the source is the manual one.
     *
     * `source` is the only optional field in any row in this configuration, and it is optional for
     * one reason only: the tabs already stored carry a title and a list of slugs and nothing else.
     * A row without it is read as {@see ProductFeedSource::legacyDefault()} — the manual source,
     * which is what a hand-picked list of slugs already was — so an existing homepage keeps working
     * and no configuration is rewritten. Any other set of fields is still refused.
     *
     * The two interact as they must: the automatic sources name their own products, so a row that
     * also carries a slug list would have two answers to the same question, and the manual source
     * with nothing in it would be a tab the storefront can never fill. So an automatic row carries
     * no slugs, and a manual row carries at least one.
     *
     * @param array<string, mixed> $tab
     */
    private static function tab(array $tab): void
    {
        if (!array_key_exists('source', $tab)) {
            $tab['source'] = ProductFeedSource::legacyDefault()->value;
        }

        $fields = array_keys($tab);
        sort($fields);
        $expected = ['slugs', 'source', 'title'];
        if ($fields !== $expected) {
            throw new \InvalidArgumentException('Invalid tabs entry.');
        }

        self::text($tab['title']);

        $source = ProductFeedSource::tryFrom(\is_string($tab['source']) ? $tab['source'] : '');
        if (null === $source) {
            throw new \InvalidArgumentException('Unknown product tab source.');
        }

        $slugs = $tab['slugs'];
        if (!is_array($slugs) || !array_is_list($slugs) || count($slugs) > 20) {
            throw new \InvalidArgumentException('Configuration list must have at most 20 entries.');
        }
        foreach ($slugs as $slug) {
            self::slug($slug);
        }

        if ($source->isManual()) {
            self::list('slugs', $slugs);

            return;
        }
        if ([] !== $slugs) {
            throw new \InvalidArgumentException('An automatic product tab takes its products from its own source.');
        }
    }

    /**
     * The theme's hero calls to action: two buttons, either of which may be left out entirely.
     *
     * @param array<string, mixed> $slide
     */
    private static function slidePairs(array $slide): void
    {
        foreach (self::SLIDE_FIELD_PAIRS as [$text, $link]) {
            if ('' === trim((string) $slide[$text]) xor '' === trim((string) $slide[$link])) {
                throw new \InvalidArgumentException('A hero call to action needs both its text and its link.');
            }
            if ('' !== trim((string) $slide[$link])) {
                self::link($slide[$link]);
            }
        }
    }
}
