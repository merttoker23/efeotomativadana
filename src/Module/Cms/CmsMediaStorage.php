<?php

namespace App\Module\Cms;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class CmsMediaStorage
{
    public function __construct(private string $directory) {}

    public function store(UploadedFile $file): string
    {
        if (!$file->isValid() || $file->getSize() > 5_000_000 || $file->getSize() < 1) {
            throw new \InvalidArgumentException('Image upload must be smaller than 5 MB.');
        }
        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->file($file->getPathname());
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
        $size = @getimagesize($file->getPathname());
        if (null === $extension || false === $size || $size[0] < 1 || $size[1] < 1 || $size[0] > 6000 || $size[1] > 6000 || $size['mime'] !== $mime) {
            throw new \InvalidArgumentException('Only valid JPEG, PNG or WebP images are allowed.');
        }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('CMS upload directory cannot be created.');
        }
        $name = bin2hex(random_bytes(16)).'.'.$extension;
        $file->move($this->directory, $name);
        return '/uploads/cms/'.$name;
    }
}
