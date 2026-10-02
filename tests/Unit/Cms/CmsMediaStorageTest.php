<?php

namespace App\Tests\Unit\Cms;

use App\Module\Cms\CmsMediaStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class CmsMediaStorageTest extends TestCase
{
    public function testStoresARealImageUnderAnUnpredictableWebPath(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cms');
        self::assertIsString($file);
        file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg=='));
        $directory = sys_get_temp_dir().'/cms-test-'.bin2hex(random_bytes(4));
        try {
            $path = (new CmsMediaStorage($directory))->store(new UploadedFile($file, 'icon.php', 'application/x-php', null, true));
            self::assertMatchesRegularExpression('~^/uploads/cms/[a-f0-9]{32}\.png$~', $path);
            self::assertFileExists($directory.'/'.basename($path));
        } finally {
            @unlink($file);
            if (isset($path)) { @unlink($directory.'/'.basename($path)); }
            @rmdir($directory);
        }
    }

    public function testRejectsAnExecutableMasqueradingAsAnImage(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cms');
        self::assertIsString($file);
        file_put_contents($file, '<?php echo "bad";');
        try {
            $upload = new UploadedFile($file, 'image.jpg', 'image/jpeg', null, true);
            $this->expectException(\InvalidArgumentException::class);
            (new CmsMediaStorage(sys_get_temp_dir().'/cms-test'))->store($upload);
        } finally {
            @unlink($file);
        }
    }

    /**
     * A shipped asset goes through the same door as an upload: a path this storage minted, from a
     * real JPEG, verified the same way, and the same file when the same asset is installed twice.
     */
    public function testInstallsAShippedAssetUnderTheSameNamingAndVerifiesIt(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'cms');
        self::assertIsString($source);
        file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg=='));
        $directory = sys_get_temp_dir().'/cms-install-'.bin2hex(random_bytes(4));
        $storage = new CmsMediaStorage($directory);

        try {
            $path = $storage->installLocalAsset($source, 'homepage-demo:hero-1.png');

            self::assertMatchesRegularExpression('~^/uploads/cms/[a-f0-9]{32}\.png$~', $path);
            self::assertTrue($storage->holds($path), 'The installed path must be one a section may reference.');
            self::assertFileExists($directory.'/'.basename($path));
            // Re-installing must land on the same file rather than leaving a second copy behind.
            self::assertSame($path, $storage->installLocalAsset($source, 'homepage-demo:hero-1.png'));
            self::assertCount(1, $storage->library());
        } finally {
            @unlink($source);
            if (is_dir($directory)) {
                foreach ((array) glob($directory.'/*') as $file) {
                    @unlink((string) $file);
                }
                @rmdir($directory);
            }
        }
    }

    /**
     * Installing an asset widens nothing. An SVG is refused exactly as an SVG upload is refused,
     * whatever key it is installed under and whatever its name suggests.
     */
    public function testRefusesToInstallAnSvgJustAsItRefusesToUploadOne(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'cms');
        self::assertIsString($source);
        file_put_contents($source, '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>');
        $directory = sys_get_temp_dir().'/cms-svg-'.bin2hex(random_bytes(4));

        try {
            $this->expectException(\InvalidArgumentException::class);
            (new CmsMediaStorage($directory))->installLocalAsset($source, 'homepage-demo:hero.svg');
        } finally {
            @unlink($source);
            @rmdir($directory);
        }
    }

    public function testRemovesOnlyWhatItInstalled(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'cms');
        self::assertIsString($source);
        file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg=='));
        $directory = sys_get_temp_dir().'/cms-remove-'.bin2hex(random_bytes(4));
        $storage = new CmsMediaStorage($directory);

        try {
            $path = $storage->installLocalAsset($source, 'homepage-demo:banner-1.png');

            self::assertFalse($storage->removeInstalled('/uploads/cms/not-a-stored-name.png'));
            self::assertFalse($storage->removeInstalled('/uploads/products/'.basename($path)));
            self::assertFileExists($directory.'/'.basename($path));
            self::assertTrue($storage->removeInstalled($path));
            self::assertFileDoesNotExist($directory.'/'.basename($path));
        } finally {
            @unlink($source);
            @rmdir($directory);
        }
    }
}
