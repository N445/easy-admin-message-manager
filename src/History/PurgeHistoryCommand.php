<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\History;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'eamm:history:purge', description: 'Deletes messenger history entries older than a number of days')]
final class PurgeHistoryCommand extends Command
{
    public function __construct(
        private readonly HistoryRepository $repository,
        private readonly int $retentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Delete entries older than this number of days', (string) $this->retentionDays)
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Only this status: handled, retrying or failed', null, array_column(HistoryStatus::cases(), 'value'))
            ->addOption('class', null, InputOption::VALUE_REQUIRED, 'Only message classes containing this text')
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, 'Only this transport')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count the entries without deleting them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $status = $input->getOption('status');
        if (null !== $status && null === HistoryStatus::tryFrom($status)) {
            $io->error(\sprintf('Unknown status "%s".', $status));

            return Command::INVALID;
        }

        $days = max(0, (int) $input->getOption('older-than'));
        $filter = HistoryFilter::fromArray(['status' => $status, 'class' => $input->getOption('class'), 'transport' => $input->getOption('transport')]);
        $dryRun = (bool) $input->getOption('dry-run');
        $count = $this->repository->purge($filter, new \DateTimeImmutable(\sprintf('-%d days', $days)), $dryRun);

        $io->success(\sprintf($dryRun ? '%d history entries older than %d days would be deleted.' : '%d history entries older than %d days deleted.', $count, $days));

        return Command::SUCCESS;
    }
}
