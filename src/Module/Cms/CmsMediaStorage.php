<?php

declare(strict_types=1);

namespace App\Module\Cms;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The only place in this application where a person hands the server a file.
 *
 * Everything here is decided from the file's own bytes, never from what the browser claimed:
 * `getClientOriginalName()` is not called anywhere in this class, and `getClientMimeType()` is
 * not either. Both are attacker-controlled, and the extension is derived from what `finfo`
 * found rather than from the name, so a payload called `photo.png` that is really PHP is
 * stored as `.jpg` and is not executable as anything.
 *
 * The storage name is 32 hex characters from `random_bytes`, so the uploaded name cannot
 * influence the path at all — which is what removes path traversal as a concern here rather
 * than checking for `../` after the fact.
 */
final readonly class CmsMediaStorage
{
    /** Matches {@see CmsMediaStorage::MAX_BYTES}; the form copy quotes the same figure. */
    public const int MAX_BYTES = 5_000_000;

    /** Refuses an image so large that decoding it is itself a denial of service. */
    private const int MAX_DIMENSION = 6000;

    /** The only file name {@see self::store()} can produce, and the only one this class reads back. */
    private const string STORED_NAME = '[a-f0-9]{32}\.(?:jpg|png|webp)';

    /** @var array<string, string> */
    private const array ALLOWED_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private string $directory) {}

    public function store(UploadedFile $file): string
    {
        if (!$file->isValid() || $file->getSize() > self::MAX_BYTES || $file->getSize() < 1) {
            throw new \InvalidArgumentException('Image upload must be smaller than 5 MB.');
        }

        $path = $file->getPathname();
        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->file($path);
        $extension = self::ALLOWED_TYPES[$mime] ?? null;
        $size = @getimagesize($path);
        // Two independent readings of the same bytes must agree. `finfo` sniffs the header and
        // `getimagesize` parses the container; a file that satisfies one and not the other is a
        // polyglot, which is exactly the shape of a payload that passes a naive check.
        if (null === $extension || false === $size || $size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_DIMENSION || $size[1] > self::MAX_DIMENSION || $size['mime'] !== $mime) {
            throw new \InvalidArgumentException('Only valid JPEG, PNG or WebP images are allowed.');
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('CMS upload directory cannot be created.');
        }

        $name = bin2hex(random_bytes(16)).'.'.$extension;
        $stored = $this->directory.'/'.$name;
        $file->move($this->directory, $name);

        // Re-read the file that actually landed on disk rather than trusting the temp copy the
        // checks above ran against. The move is the last moment at which the bytes could still
        // differ, and this closes that window instead of assuming it.
        $storedMime = (new \finfo(\FILEINFO_MIME_TYPE))->file($stored);
        if ($storedMime !== $mime) {
            @unlink($stored);

            throw new \InvalidArgumentException('The stored file did not match its uploaded type.');
        }

        // 0644 explicitly rather than at the mercy of the umask: a restrictive umask in a cron
        // or a worker container would otherwise leave stored images unreadable by the web
        // server, and the upload would "succeed" while rendering as a broken image.
        @chmod($stored, 0644);

        return '/uploads/cms/'.$name;
    }

    /**
     * The images already uploaded, newest first, so a section form can offer a picker instead of
     * making an administrator paste a stored path into a text box.
     *
     * The directory is configuration rather than request input, but the listing is still filtered
     * down to exactly the names this class itself mints. Anything else in the folder — a
     * half-written upload, an operator's own copy, a file dropped by a deployment — is invisible
     * here, and therefore cannot be attached to a section.
     *
     * @return list<array{path: string, filename: string, bytes: int, modified: int}>
     */
    public function library(int $limit = 60): array
    {
        $limit = max(1, $limit);
        if (!is_dir($this->directory)) {
            return [];
        }

        $entries = @scandir($this->directory);
        if (false === $entries) {
            return [];
        }

        $images = [];
        foreach ($entries as $entry) {
            if (1 !== preg_match('~^'.self::STORED_NAME.'$~', $entry)) {
                continue;
            }
            $file = $this->directory.'/'.$entry;
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }
            $images[] = [
                'path' => '/uploads/cms/'.$entry,
                'filename' => $entry,
                'bytes' => (int) @filesize($file),
                'modified' => (int) @filemtime($file),
            ];
        }

        usort($images, static fn (array $a, array $b): int => $b['modified'] <=> $a['modified'] ?: strcmp($b['filename'], $a['filename']));

        return \array_slice($images, 0, $limit);
    }

    /** True when the path names an image this storage could have written. */
    public function holds(string $path): bool
    {
        return 1 === preg_match('~^/uploads/cms/'.self::STORED_NAME.'$~', $path);
    }
}
