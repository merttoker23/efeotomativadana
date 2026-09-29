<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Cms\CmsMediaStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The upload path, tested as an attacker would use it.
 *
 * Every case here is a file an authenticated staff member — or somebody who has found a
 * session — could submit, and every assertion is about what must NOT happen: the file must not
 * be stored, must not be stored under the name it arrived with, or must not be stored under an
 * extension a web server would execute.
 *
 * The last group matters most. `CmsMediaStorage` decides everything from the bytes on disk,
 * never from `getClientOriginalName()` or `getClientMimeType()`; these tests pass hostile values
 * for both and prove that neither changes the outcome.
 */
final class UploadHardeningTest extends TestCase
{
    /** A real, valid 1x1 PNG. Deliberately the only fixture that is a genuine image. */
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $directories = [];

    private ?string $sharedDirectory = null;

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
        $this->directories = [];
        $this->sharedDirectory = null;
    }

    public function testTheStoredNameComesFromTheBytesAndNotFromTheSubmittedName(): void
    {
        // A valid PNG submitted under a .php name and claiming to be PHP. It is stored — the
        // bytes really are an image — and it lands as .png under a random name, which is the
        // whole point: neither the extension nor the name came from the client.
        $path = $this->store($this->png(), 'shell.php', 'application/x-php');

        self::assertMatchesRegularExpression('~^/uploads/cms/[a-f0-9]{32}\.png$~', $path);
    }

    public function testAPhpPayloadIsRefusedEvenWhenTheNameAndTheClaimAgree(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store('<?php echo "bad";', 'image.jpg', 'image/jpeg');
    }

    /**
     * A path-traversal attempt in the submitted name. It cannot work here because the name is
     * discarded, and this test proves that rather than asserting it: the file lands inside the
     * media directory and nothing appears above it.
     */
    public function testATraversalNameCannotPlaceAFileOutsideTheMediaDirectory(): void
    {
        $directory = $this->sharedDirectory();
        $path = $this->store($this->png(), '../../../../etc/passwd.png', 'image/png');

        self::assertFileExists($directory.'/'.basename($path));
        self::assertFileDoesNotExist(dirname($directory, 3).'/passwd.png');
    }

    /**
     * A null byte in the submitted name. The safe outcome is that it is ignored, not that the
     * upload is refused: the name is never used, so refusing would be a false alarm. If this
     * ever starts throwing, that is a behaviour change worth understanding rather than
     * accepting quietly.
     */
    public function testANullByteInTheNameIsIgnoredRatherThanTruncatingTheStoredName(): void
    {
        $path = $this->store($this->png(), "image.png\0.php", 'image/png');

        self::assertStringEndsWith('.png', $path);
        self::assertStringNotContainsString("\0", $path);
        self::assertStringNotContainsString('.php', $path);
    }

    #[DataProvider('nonImagePayloads')]
    public function testANonImageIsRefused(string $bytes, string $claimedName, string $claimedType): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store($bytes, $claimedName, $claimedType);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function nonImagePayloads(): iterable
    {
        yield 'php source' => ['<?php system($_GET["c"]);', 'a.jpg', 'image/jpeg'];
        yield 'shell script' => ["#!/bin/sh\nrm -rf /\n", 'a.png', 'image/png'];
        yield 'html with a script tag' => ['<html><script>alert(1)</script></html>', 'a.png', 'image/png'];
        yield 'a zip archive' => ["PK\x03\x04\x14\x00\x00\x00\x00\x00", 'a.png', 'image/png'];
        yield 'a pdf' => ['%PDF-1.4'."\n".str_repeat('x', 64), 'a.png', 'image/png'];
        yield 'an svg, which is an image the browser will execute' => [
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'a.svg',
            'image/svg+xml',
        ];
    }

    public function testAnEmptyFileIsRefusedRatherThanStoredAsAZeroByteImage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store('', 'a.png', 'image/png');
    }

    public function testTheStoredFileIsReadableByTheWebServerEvenUnderARestrictiveUmask(): void
    {
        // The media directory is bind-mounted from the host and read by a web server that
        // frequently runs as a different user. A restrictive umask in the web process or a cron
        // worker would otherwise leave stored images unreadable, and the upload would "succeed"
        // while rendering as a broken image.
        $previous = umask(0077);

        try {
            $path = $this->store($this->png(), 'a.png', 'image/png');
            $mode = fileperms($this->sharedDirectory().'/'.basename($path)) & 0777;

            self::assertSame(0644, $mode, 'A stored image must stay readable by the web server.');
        } finally {
            umask($previous);
        }
    }

    private function png(): string
    {
        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);

        return $bytes;
    }

    private function store(string $bytes, string $name, string $clientMimeType): string
    {
        $file = tempnam(sys_get_temp_dir(), 'upload');
        self::assertIsString($file);
        file_put_contents($file, $bytes);

        try {
            return (new CmsMediaStorage($this->sharedDirectory()))
                ->store(new UploadedFile($file, $name, $clientMimeType, null, true));
        } finally {
            @unlink($file);
        }
    }

    /**
     * The directory this test class stores into, created once per test.
     *
     * Shared deliberately: a test that wants to reason about where a file landed has to name
     * the same directory the store was given, and a per-call random name would make that a
     * guess.
     */
    private function sharedDirectory(): string
    {
        return $this->sharedDirectory ??= $this->directory();
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir().'/upload-test-'.bin2hex(random_bytes(6));
        $this->directories[] = $directory;

        return $directory;
    }
}
