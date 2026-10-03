<?php

declare(strict_types=1);

namespace App\Module\Catalog;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class BrandLogoStorage
{
    public function __construct(private string $directory, private ValidatorInterface $validator)
    {
    }

    public static function uploadConstraint(): Image
    {
        return new Image(
            maxSize: 5_000_000,
            mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
            maxWidth: 2048,
            maxHeight: 2048,
            // Header size/type checks must finish before decoding any pixels below.
            detectCorrupted: false,
            mimeTypesMessage: 'Geçerli bir JPEG, PNG veya WebP logo seçin.',
        );
    }

    public function store(int $brandId, UploadedFile $file): void
    {
        if ($brandId < 1 || !$file->isValid() || count($this->validator->validate($file, self::uploadConstraint())) > 0) {
            throw new \InvalidArgumentException('Geçerli bir JPEG, PNG veya WebP logo seçin (en fazla 5 MB ve 2048 × 2048 piksel).');
        }

        $bytes = file_get_contents($file->getPathname());
        $source = false === $bytes ? false : @imagecreatefromstring($bytes);
        if (false === $source) {
            throw new \InvalidArgumentException('Logo görseli okunamadı.');
        }

        // Re-encode real image pixels as JPEG; client filenames and embedded metadata
        // never become part of the stored file. Flatten transparent logos onto white.
        $canvas = imagecreatetruecolor(imagesx($source), imagesy($source));
        if (false === $canvas) {
            throw new \RuntimeException('Logo görseli hazırlanamadı.');
        }
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Logo klasörü oluşturulamadı.');
        }
        $temporary = tempnam($this->directory, '.brand-logo-');
        if (false === $temporary) {
            throw new \RuntimeException('Logo dosyası hazırlanamadı.');
        }
        try {
            if (!imagejpeg($canvas, $temporary, 90) || !@chmod($temporary, 0644) || !@rename($temporary, $this->directory.'/'.$brandId.'.jpg')) {
                throw new \RuntimeException('Logo kaydedilemedi. Lütfen tekrar deneyin.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function snapshot(int $brandId): ?string
    {
        $file = $this->directory.'/'.$brandId.'.jpg';
        if (!is_file($file)) {
            return null;
        }
        $bytes = file_get_contents($file);
        if (false === $bytes) {
            throw new \RuntimeException('Mevcut logo okunamadı; logo değiştirilemedi.');
        }

        return $bytes;
    }

    /** Restore the previous file if the accompanying database transaction fails. */
    public function restore(int $brandId, ?string $previous): void
    {
        $file = $this->directory.'/'.$brandId.'.jpg';
        if (null === $previous) {
            if (is_file($file) && !@unlink($file)) {
                throw new \RuntimeException('Logo değişikliği geri alınamadı.');
            }

            return;
        }
        $temporary = tempnam($this->directory, '.brand-logo-');
        if (false === $temporary) {
            throw new \RuntimeException('Önceki logo geri yüklenemedi.');
        }
        try {
            if (file_put_contents($temporary, $previous) !== strlen($previous) || !@chmod($temporary, 0644) || !@rename($temporary, $file)) {
                throw new \RuntimeException('Önceki logo geri yüklenemedi.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function uploadedPath(int $brandId): ?string
    {
        $file = $this->directory.'/'.$brandId.'.jpg';
        if ($brandId < 1 || !is_file($file) || !is_readable($file)) {
            return null;
        }
        $version = hash_file('sha256', $file);

        return false === $version ? null : '/uploads/cms/img/ureticiler/'.$brandId.'.jpg?v='.substr($version, 0, 16);
    }
}
