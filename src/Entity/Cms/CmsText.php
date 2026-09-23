<?php

namespace App\Entity\Cms;

final class CmsText
{
    public static function validate(string $title, string $slug, string $body): void
    {
        if ('' === trim($title) || mb_strlen($title) > 255 || 1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 255 || '' === trim($body)) {
            throw new \InvalidArgumentException('Title, slug or body is invalid.');
        }
    }
}
