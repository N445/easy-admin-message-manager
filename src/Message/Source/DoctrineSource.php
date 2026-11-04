<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Message\Source;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\MessageView;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final readonly class DoctrineSource implements MessageSource
{
    public function __construct(
        private Connection $connection,
        private string $table,
        private string $queue,
        private SerializerInterface $serializer,
        private string $transport,
    ) {
    }

    public function count(MessageFilter $filter): int
    {
        return (int) $this->query($filter)->select('COUNT(*)')->executeQuery()->fetchOne();
    }

    public function slice(MessageFilter $filter, int $offset, int $limit): array
    {
        $page = $this->query($filter)
            ->select('id')
            ->orderBy('id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $rows = $this->connection->createQueryBuilder()
            ->select('m.id', 'm.body', 'm.headers')
            ->from($this->table, 'm')
            ->innerJoin('m', '('.$page->getSQL().')', 'eamm_page', 'eamm_page.id = m.id')
            ->orderBy('m.id', 'DESC')
            ->setParameters($page->getParameters(), $page->getParameterTypes())
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->view(...), $rows);
    }

    public function ids(MessageFilter $filter): array
    {
        return array_map(strval(...), $this->query($filter)->select('id')->executeQuery()->fetchFirstColumn());
    }

    public function remove(MessageFilter $filter): int
    {
        $query = $this->connection->createQueryBuilder()
            ->delete($this->table)
            ->where('queue_name = :eamm_queue')
            ->setParameter('eamm_queue', $this->queue);

        return (int) (new DoctrineFilter($filter))->apply($query)->executeStatement();
    }

    public function isComplete(): bool
    {
        return true;
    }

    private function query(MessageFilter $filter): QueryBuilder
    {
        $query = $this->connection->createQueryBuilder()
            ->from($this->table)
            ->where('queue_name = :eamm_queue')
            ->setParameter('eamm_queue', $this->queue);

        return (new DoctrineFilter($filter))->apply($query);
    }

    /**
     * @param array{id: int|string, body: string, headers: string} $row
     */
    private function view(array $row): MessageView
    {
        return MessageView::fromEnvelope($this->decode($row), $this->transport);
    }

    /**
     * @param array{id: int|string, body: string, headers: string} $row
     */
    private function decode(array $row): Envelope
    {
        $data = ['body' => $row['body'], 'headers' => json_decode($row['headers'], true) ?? []];
        $stamp = new TransportMessageIdStamp($row['id']);
        $phpSerializer = $this->serializer instanceof PhpSerializer ? $this->serializer : null;
        $phpSerializer?->acceptPhpIncompleteClass();

        try {
            return $this->serializer->decode($data)->withoutAll(TransportMessageIdStamp::class)->with($stamp);
        } catch (MessageDecodingFailedException $exception) {
            return MessageDecodingFailedException::wrap($data, $exception->getMessage(), $exception->getCode(), $exception)->with($stamp);
        } finally {
            $phpSerializer?->rejectPhpIncompleteClass();
        }
    }
}
