<?php

namespace App\Tests\Unit\Integration\B2b;

use App\Module\Integration\B2b\B2bSnapshotCleaner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class B2bSnapshotCleanerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/efe-b2b-cleaner-'.bin2hex(random_bytes(5));
        mkdir($this->directory, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testItRemovesPartialAndExpiredSnapshotsButKeepsRecentFailedEvidence(): void
    {
        $now = new \DateTimeImmutable('2026-07-25T12:00:00+00:00');
        $old = $this->directory.'/old.json';
        $recent = $this->directory.'/recent.json';
        $partial = $this->directory.'/active.json.part';
        file_put_contents($old, '{}');
        file_put_contents($recent, '{}');
        file_put_contents($partial, '{}');
        touch($old, $now->modify('-8 days')->getTimestamp());
        touch($recent, $now->modify('-1 day')->getTimestamp());
        $cleaner = new B2bSnapshotCleaner($this->directory, new MockClock($now));

        $cleaner->pruneExpired($this->directory);

        self::assertFileDoesNotExist($old);
        self::assertFileExists($recent);
        self::assertFileDoesNotExist($partial);
    }

    public function testItCanonicalizesARelativeRootBeforeItExists(): void
    {
        $relative = 'efe-b2b-relative-'.bin2hex(random_bytes(5));
        $cleaner = new B2bSnapshotCleaner($relative, new MockClock('2026-07-25T12:00:00+00:00'));
        mkdir($relative, 0755, true);
        $snapshot = $relative.'/completed.json';
        file_put_contents($snapshot, '{}');

        try {
            $cleaner->remove($snapshot);
            self::assertFileDoesNotExist($snapshot);
        } finally {
            @unlink($snapshot);
            @rmdir($relative);
        }
    }

    public function testItCanonicalizesARelativeDirectoryBeforeItExists(): void
    {
        $relative = 'efe-b2b-relative-prune-'.bin2hex(random_bytes(5));
        $cleaner = new B2bSnapshotCleaner($relative, new MockClock('2026-07-25T12:00:00+00:00'));
        $directory = $relative.'/nested';

        $cleaner->pruneExpired($directory);
        self::assertDirectoryDoesNotExist($directory);
    }

    public function testItRemovesCompletedSnapshotButRefusesPathsOutsideItsRoot(): void
    {
        $inside = $this->directory.'/completed.json';
        $outside = sys_get_temp_dir().'/efe-b2b-outside-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($inside, '{}');
        file_put_contents($outside, '{}');
        $cleaner = new B2bSnapshotCleaner($this->directory, new MockClock('2026-07-25T12:00:00+00:00'));
        try {
            $cleaner->remove($inside);
            self::assertFileDoesNotExist($inside);

            $this->expectException(\InvalidArgumentException::class);
            $cleaner->remove($outside);
        } finally {
            @unlink($outside);
        }
    }
}
