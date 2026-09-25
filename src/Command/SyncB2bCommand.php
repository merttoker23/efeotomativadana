<?php

namespace App\Command;

use App\Module\Integration\B2b\B2bDispatchStatus;
use App\Module\Integration\B2b\B2bSyncMode;
use App\Module\Integration\B2b\B2bSyncServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:b2b:sync', description: 'Queue an Efe B2B FULL or DAILY synchronization.')]
final class SyncB2bCommand extends Command
{
    public function __construct(private readonly B2bSyncServiceInterface $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Sync mode: full or daily');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mode = B2bSyncMode::tryFrom(strtolower(trim((string) $input->getOption('mode'))));
        if (null === $mode) {
            $io->error('The --mode option must be full or daily.');

            return self::FAILURE;
        }
        $result = $this->service->request($mode);
        if (B2bDispatchStatus::Disabled === $result->status()) {
            $io->note('B2B integration is disabled; no synchronization was queued.');

            return self::SUCCESS;
        }
        if (B2bDispatchStatus::Rejected === $result->status()) {
            $io->error((string) $result->reason());

            return self::FAILURE;
        }
        if (B2bDispatchStatus::Coalesced === $result->status()) {
            $io->note(sprintf('B2B run %d is already active; no duplicate job was queued.', $result->runId() ?? 0));

            return self::SUCCESS;
        }
        $io->success(sprintf('B2B %s run %d was queued.', $mode->value, $result->runId() ?? 0));

        return self::SUCCESS;
    }
}
