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
}
