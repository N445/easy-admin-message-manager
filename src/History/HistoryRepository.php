<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\History;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use EasyAdminMessengerManager\History\Schema\HistoryTable;

final readonly class HistoryRepository
{
    private const string LIST_COLUMNS = 'id, message_id, message_class, transport, status, attempt, duration_ms, memory_bytes, error_class, error_message, handled_at';

    public function __construct(private Connection $connection)
    {
    }

    public function insert(HistoryEntry $entry): void
    {
        $this->connection->insert(HistoryTable::NAME, $entry->toRow(new \DateTimeImmutable()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function page(HistoryFilter $filter, int $page, int $perPage): array
    {
        return $filter->apply($this->select(self::LIST_COLUMNS))
            ->orderBy('id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function timeline(string $messageId, int $limit): array
    {
        return $this->page(new HistoryFilter(messageId: $messageId), 1, $limit);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->select('*')->where('id = :id')->setParameter('id', $id)->executeQuery()->fetchAssociative();

        return false === $row ? null : $row;
    }

    public function count(HistoryFilter $filter): int
    {
        return (int) $filter->apply($this->select('COUNT(*)'))->executeQuery()->fetchOne();
    }

    /**
     * @return array<string, int>
     */
    public function statusCounts(\DateTimeImmutable $since): array
    {
        $rows = $this->since($this->select('status, COUNT(*) AS total'), $since)
            ->groupBy('status')
            ->executeQuery()
            ->fetchAllKeyValue();

        return array_map(intval(...), $rows);
    }

    /**
     * @return list<array{message_class: string, total: int, failures: int, avg_ms: float|null}>
     */
    public function classStats(\DateTimeImmutable $since, int $limit): array
    {
        return $this->since($this->select("message_class, COUNT(*) AS total, SUM(CASE WHEN status <> 'handled' THEN 1 ELSE 0 END) AS failures, AVG(duration_ms) AS avg_ms"), $since)
            ->groupBy('message_class')
            ->orderBy('failures', 'DESC')
            ->addOrderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function purge(HistoryFilter $filter, \DateTimeImmutable $before, bool $dryRun): int
    {
        $query = $dryRun
            ? $this->select('COUNT(*)')
            : $this->connection->createQueryBuilder()->delete(HistoryTable::NAME);
        $query = $filter->apply($query)->andWhere('handled_at < :before')->setParameter('before', $before->format('Y-m-d H:i:s'));

        return $dryRun ? (int) $query->executeQuery()->fetchOne() : (int) $query->executeStatement();
    }

    private function select(string $columns): QueryBuilder
    {
        return $this->connection->createQueryBuilder()->select($columns)->from(HistoryTable::NAME);
    }

    private function since(QueryBuilder $query, \DateTimeImmutable $since): QueryBuilder
    {
        return $query->where('handled_at >= :since')->setParameter('since', $since->format('Y-m-d H:i:s'));
    }
}
