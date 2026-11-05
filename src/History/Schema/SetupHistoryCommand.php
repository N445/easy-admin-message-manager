<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\History\Schema;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'eamm:history:setup', description: 'Creates the Messenger history table when it does not exist yet')]
final class SetupHistoryCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Print the SQL instead of running it')
            ->setHelp('Use it when your application does not manage its schema with Doctrine migrations. With migrations, run <info>doctrine:migrations:diff</info> instead: the table is part of the generated schema.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $statements = $this->connection->getDatabasePlatform()->getCreateTableSQL(HistoryTable::definition());
        if ($input->getOption('dump-sql')) {
            $io->writeln(array_map(static fn (string $statement): string => $statement.';', $statements));

            return Command::SUCCESS;
        }

        if ($this->connection->createSchemaManager()->tablesExist([HistoryTable::NAME])) {
            $io->success(\sprintf('The "%s" table already exists.', HistoryTable::NAME));

            return Command::SUCCESS;
        }

        array_map($this->connection->executeStatement(...), $statements);
        $io->success(\sprintf('Created the "%s" table.', HistoryTable::NAME));

        return Command::SUCCESS;
    }
}
