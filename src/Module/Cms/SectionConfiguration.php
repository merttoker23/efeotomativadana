<?php

namespace App\Module\Cms;

final class SectionConfiguration
{
    /** @param array<string, mixed> $config
     *  @return array<string, mixed>
     */
    public static function validate(HomeSectionType $type, array $config): array
    {
        $schema = match ($type) {
            HomeSectionType::AnnouncementBar => ['text' => 'text'],
            HomeSectionType::HeroSlider => ['slides' => 'slides'],
            HomeSectionType::CategoryMenu => ['slugs' => 'slugs'],
            HomeSectionType::ProductCarousel => ['slugs' => 'slugs'],
            HomeSectionType::BannerGrid => ['banners' => 'banners'],
            HomeSectionType::Features => ['features' => 'features'],
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
            } elseif ('limit' === $kind) {
                if (!is_int($value) || $value < 1 || $value > 12) {
                    throw new \InvalidArgumentException('Blog limit must be between 1 and 12.');
                }
            } else {
                self::list($kind, $value);
            }
        }

        return $config;
    }

    private static function text(mixed $value): void
    {
        if (!is_string($value) || '' === trim($value) || mb_strlen($value) > 500) {
            throw new \InvalidArgumentException('Text must contain between 1 and 500 characters.');
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
                'slides' => ['title', 'description', 'image', 'link'],
                'banners' => ['title', 'image', 'link'],
                'features' => ['title', 'description'],
                'tabs' => ['title', 'slugs'],
                'quotes' => ['author', 'text'],
                default => throw new \LogicException('Unknown configuration list.'),
            };
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Invalid '.$kind.' entry.');
            }
            $actualFields = array_keys($item);
            sort($actualFields);
            $expectedFields = $fields;
            sort($expectedFields);
            if ($actualFields !== $expectedFields) { throw new \InvalidArgumentException('Invalid '.$kind.' entry.'); }
            foreach ($fields as $field) {
                match ($field) {
                    'image' => self::image($item[$field]),
                    'link' => self::link($item[$field]),
                    'slugs' => self::list('slugs', $item[$field]),
                    default => self::text($item[$field]),
                };
            }
        }
    }
}
