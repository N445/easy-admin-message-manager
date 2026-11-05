<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use EasyAdminMessengerManager\History\Schema\HistoryTable;
use EasyAdminMessengerManager\Tests\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class HistorySchemaTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testTheHistoryTableIsPartOfTheGeneratedOrmSchema(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $table = (new SchemaTool($entityManager))->getSchemaFromMetadata([])->getTable(HistoryTable::NAME);

        self::assertTrue($table->hasColumn('error_trace'));
        self::assertTrue($table->hasIndex('eamm_history_status_idx'));
        self::assertTrue($table->hasColumn('message_id'));
        self::assertTrue($table->hasIndex('eamm_history_message_id_idx'));
    }

    public function testSetupCommandDumpsTheSql(): void
    {
        $tester = $this->setupCommand();

        self::assertSame(Command::SUCCESS, $tester->execute(['--dump-sql' => true]));

        self::assertStringContainsString('CREATE TABLE eamm_message_history', $tester->getDisplay());
        self::assertFalse($this->connection()->createSchemaManager()->tablesExist([HistoryTable::NAME]));
    }

    public function testSetupCommandCreatesTheTableOnce(): void
    {
        $tester = $this->setupCommand();

        $tester->execute([]);
        self::assertTrue($this->connection()->createSchemaManager()->tablesExist([HistoryTable::NAME]));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }

    private function setupCommand(): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find('eamm:history:setup'));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }
}
