<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use EasyAdminMessengerManager\History\Schema\HistoryTable;

final class Version20261104000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'EasyAdmin Messenger Manager: create the eamm_message_history table.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(HistoryTable::NAME), 'eamm_message_history already exists.');

        foreach ($this->platform->getCreateTableSQL(HistoryTable::definition()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL(HistoryTable::NAME));
    }
}
