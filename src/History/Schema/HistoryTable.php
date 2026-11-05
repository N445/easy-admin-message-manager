<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\History\Schema;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Name\Identifier;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

final class HistoryTable
{
    public const string NAME = 'eamm_message_history';

    public static function definition(): Table
    {
        return Table::editor()
            ->setUnquotedName(self::NAME)
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::BIGINT)->setAutoincrement(true)->create(),
                self::string('message_class', 255, false),
                self::string('transport', 100, false),
                self::string('status', 20, false),
                self::column('attempt', Types::SMALLINT, false),
                self::column('duration_ms', Types::INTEGER, true),
                self::column('memory_bytes', Types::INTEGER, true),
                self::string('error_class', 255, true),
                self::column('error_message', Types::TEXT, true),
                self::column('error_trace', Types::TEXT, true),
                self::column('payload', Types::TEXT, true),
                self::column('handled_at', Types::DATETIME_IMMUTABLE, false),
                self::string('message_id', 36, true),
            )
            ->setIndexes(
                self::index('eamm_history_handled_at_idx', 'handled_at'),
                self::index('eamm_history_class_idx', 'message_class'),
                self::index('eamm_history_status_idx', 'status', 'handled_at'),
                self::index('eamm_history_message_id_idx', 'message_id'),
            )
            ->setPrimaryKeyConstraint(new PrimaryKeyConstraint(null, [new UnqualifiedName(Identifier::unquoted('id'))], true))
            ->create();
    }

    public static function addTo(Schema $schema): Schema
    {
        if ($schema->hasTable(self::NAME)) {
            return $schema;
        }

        return $schema->edit()->addTable(self::definition())->create();
    }

    private static function column(string $name, string $type, bool $nullable): Column
    {
        return Column::editor()->setUnquotedName($name)->setTypeName($type)->setNotNull(!$nullable)->create();
    }

    private static function string(string $name, int $length, bool $nullable): Column
    {
        return Column::editor()->setUnquotedName($name)->setTypeName(Types::STRING)->setLength($length)->setNotNull(!$nullable)->create();
    }

    private static function index(string $name, string ...$columns): Index
    {
        return Index::editor()->setUnquotedName($name)->setUnquotedColumnNames(...$columns)->create();
    }
}
