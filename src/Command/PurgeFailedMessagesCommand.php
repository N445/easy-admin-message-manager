<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Command;

use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\Source\MessagePages;
use EasyAdminMessengerManager\Message\Source\MessageSources;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'eamm:failed:purge', description: 'Deletes failed messages matching the given criteria from the failure transports')]
final class PurgeFailedMessagesCommand extends Command
{
    public function __construct(private readonly MessageSources $sources)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Only messages that failed more than this number of days ago')
            ->addOption('class', null, InputOption::VALUE_REQUIRED, 'Only messages whose content contains this class name')
            ->addOption('exception', null, InputOption::VALUE_REQUIRED, 'Only messages whose content contains this exception text')
            ->addOption('origin', null, InputOption::VALUE_REQUIRED, 'Only messages coming from this transport')
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, 'Only this failure transport (all of them by default)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count the messages without deleting them')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Do not ask for confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filter = $this->filter($input);
        $pages = $this->pages($input);
        $count = $pages->count($filter);

        if ($input->getOption('dry-run') || 0 === $count) {
            $io->success(\sprintf('%d failed messages match.', $count));

            return Command::SUCCESS;
        }

        if (!$input->getOption('force') && !$io->confirm(\sprintf('Delete %d failed messages?', $count), false)) {
            return Command::SUCCESS;
        }

        $io->success(\sprintf('%d failed messages deleted.', $pages->remove($filter)));

        return Command::SUCCESS;
    }

    private function filter(InputInterface $input): MessageFilter
    {
        $olderThan = $input->getOption('older-than');

        return new MessageFilter(
            $this->text($input, 'class'),
            $this->text($input, 'origin'),
            $this->text($input, 'exception'),
            null,
            null === $olderThan ? null : new \DateTimeImmutable(\sprintf('-%d days', max(0, (int) $olderThan))),
        );
    }

    private function pages(InputInterface $input): MessagePages
    {
        $transport = $this->text($input, 'transport');

        return null === $transport ? $this->sources->failed() : $this->sources->failedIn($transport);
    }

    private function text(InputInterface $input, string $option): ?string
    {
        $value = trim((string) $input->getOption($option));

        return '' === $value ? null : $value;
    }
}
