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
 *
 * {@see self::installLocalAsset()} is the one way in that is not a person's upload: it puts a
 * shipped asset here so a section can be created with content already in it. It reads the same
 * bytes through the same verification, so it is a second door into the same room rather than a
 * second set of rules.
 */
final readonly class CmsMediaStorage
{
    /** Matches {@see CmsMediaStorage::MAX_BYTES}; the form copy quotes the same figure. */
    public const int MAX_BYTES = 5_000_000;

    /** Refuses an image so large that decoding it is itself a denial of service. */
    private const int MAX_DIMENSION = 6000;

    /**
     * 0644 explicitly rather than at the mercy of the umask: a restrictive umask in a cron or a
     * worker container would otherwise leave stored images unreadable by the web server, and the
     * upload would "succeed" while rendering as a broken image.
     */
    private const int READABLE_MODE = 0644;

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

        $verified = $this->verify($file->getPathname());

        $this->prepareDirectory();

        $name = bin2hex(random_bytes(16)).'.'.$verified['extension'];
        $stored = $this->directory.'/'.$name;
        $file->move($this->directory, $name);

        // Re-read the file that actually landed on disk rather than trusting the temp copy the
        // checks above ran against. The move is the last moment at which the bytes could still
        // differ, and this closes that window instead of assuming it.
        $storedMime = (new \finfo(\FILEINFO_MIME_TYPE))->file($stored);
        if ($storedMime !== $verified['mime']) {
            @unlink($stored);

            throw new \InvalidArgumentException('The stored file did not match its uploaded type.');
        }

        @chmod($stored, self::READABLE_MODE);

        return '/uploads/cms/'.$name;
    }

    /**
     * Copy a shipped, checked-in image into this storage under a name this storage would have minted.
     *
     * The section configuration model only accepts an uploaded CMS image, so a section that ships
     * with content has to own a real file in this directory rather than a reference to something
     * served from the asset pipeline. The name is derived from `$key` instead of from randomness,
     * so installing the same asset twice lands on the same file rather than accumulating copies,
     * and the returned path is one {@see self::holds()} and `SectionConfiguration` both accept.
     *
     * The bytes are verified exactly as an upload's are. Nothing here widens what this storage
     * accepts: the allowance stays JPEG, PNG and WebP, and an SVG is refused here for the same
     * reason it is refused there.
     */
    public function installLocalAsset(string $source, string $key): string
    {
        $key = trim($key);
        if ('' === $key || !is_file($source) || !is_readable($source)) {
            throw new \InvalidArgumentException('The source asset is not a readable local file.');
        }

        $bytes = (int) filesize($source);
        if ($bytes < 1 || $bytes > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Image must be smaller than 5 MB.');
        }
        $verified = $this->verify($source);
        $this->prepareDirectory();

        $name = substr(hash('sha256', 'cms-local-asset:'.$key), 0, 32).'.'.$verified['extension'];
        $stored = $this->directory.'/'.$name;

        if (!is_file($stored)) {
            if (!@copy($source, $stored)) {
                throw new \RuntimeException('The local asset could not be installed.');
            }

            $landed = (new \finfo(\FILEINFO_MIME_TYPE))->file($stored);
            $landedSize = @getimagesize($stored);
            if ($landed !== $verified['mime'] || false === $landedSize || $landedSize['mime'] !== $verified['mime']) {
                @unlink($stored);

                throw new \RuntimeException('The installed asset did not match its source type.');
            }

            @chmod($stored, self::READABLE_MODE);
        }

        return '/uploads/cms/'.$name;
    }

    /**
     * Take back an asset {@see self::installLocalAsset()} put here.
     *
     * A caller that fails halfway through a multi-step write uses this so a rejected run leaves
     * nothing behind in the media library. It can only ever name a file in this class's own
     * directory whose name this class mints, so it cannot become a way to delete anything else.
     */
    public function removeInstalled(string $path): bool
    {
        if (!$this->holds($path)) {
            return false;
        }

        $file = $this->directory.'/'.basename($path);

        return is_file($file) && @unlink($file);
    }

    /**
     * Two independent readings of the same bytes must agree.
     *
     * `finfo` sniffs the header and `getimagesize` parses the container; a file that satisfies one
     * and not the other is a polyglot, which is exactly the shape of a payload that passes a naive
     * check. The extension this storage will use comes from what `finfo` found, never from a name.
     *
     * @return array{mime: string, extension: string}
     */
    private function verify(string $path): array
    {
        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->file($path);
        $extension = self::ALLOWED_TYPES[$mime] ?? null;
        $size = @getimagesize($path);

        if (null === $extension || false === $size || $size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_DIMENSION || $size[1] > self::MAX_DIMENSION || $size['mime'] !== $mime) {
            throw new \InvalidArgumentException('Only valid JPEG, PNG or WebP images are allowed.');
        }

        return ['mime' => $mime, 'extension' => $extension];
    }

    private function prepareDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('CMS upload directory cannot be created.');
        }
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
