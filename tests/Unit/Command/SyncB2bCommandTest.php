<?php

namespace App\Tests\Unit\Command;

use App\Command\SyncB2bCommand;
use App\Module\Integration\B2b\B2bDispatchResult;
use App\Module\Integration\B2b\B2bDispatchStatus;
use App\Module\Integration\B2b\B2bSyncMode;
use App\Module\Integration\B2b\B2bSyncServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncB2bCommandTest extends TestCase
{
    public function testQueuedRequestPrintsRunId(): void
    {
        $tester = $this->tester(B2bDispatchResult::queued(42, B2bSyncMode::Full));

        self::assertSame(Command::SUCCESS, $tester->execute(['--mode' => 'full']));
        self::assertStringContainsString('42', $tester->getDisplay());
        self::assertStringContainsString('queued', $tester->getDisplay());
    }

    #[DataProvider('coalescedModes')]
    public function testCoalescedRequestReturnsSuccess(string $mode): void
    {
        $result = B2bDispatchResult::coalesced(42, B2bSyncMode::from(strtolower($mode)));
        $tester = $this->tester($result);

        self::assertSame(Command::SUCCESS, $tester->execute(['--mode' => $mode]));
        self::assertStringContainsString('already active', $tester->getDisplay());
    }

    /** @return iterable<string, array{string}> */
    public static function coalescedModes(): iterable
    {
        yield 'full' => ['full'];
        yield 'daily' => ['daily'];
    }

    public function testDisabledRequestIsSafeSuccess(): void
    {
        $tester = $this->tester(B2bDispatchResult::disabled());

        self::assertSame(Command::SUCCESS, $tester->execute(['--mode' => 'daily']));
        self::assertStringContainsString('disabled', $tester->getDisplay());
    }

    public function testRejectedRequestReturnsFailure(): void
    {
        $tester = $this->tester(B2bDispatchResult::rejected('unsupported provider'));

        self::assertSame(Command::FAILURE, $tester->execute(['--mode' => 'full']));
        self::assertStringContainsString('unsupported provider', $tester->getDisplay());
    }

    public function testInvalidModeIsRejectedBeforeCallingService(): void
    {
        $service = new class implements B2bSyncServiceInterface {
            public function request(B2bSyncMode $mode): B2bDispatchResult
            {
                throw new \LogicException('Service must not be called for an invalid mode.');
            }
        };
        $tester = new CommandTester(new SyncB2bCommand($service));

        self::assertSame(Command::FAILURE, $tester->execute(['--mode' => 'hourly']));
        self::assertStringContainsString('full or daily', $tester->getDisplay());
    }

    private function tester(B2bDispatchResult $result): CommandTester
    {
        $service = new class($result) implements B2bSyncServiceInterface {
            public function __construct(private B2bDispatchResult $result) {}
            public function request(B2bSyncMode $mode): B2bDispatchResult { return $this->result; }
        };

        return new CommandTester(new SyncB2bCommand($service));
    }
}
