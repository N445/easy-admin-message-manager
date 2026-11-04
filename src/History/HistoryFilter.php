<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\History;

use Doctrine\DBAL\Query\QueryBuilder;

final readonly class HistoryFilter
{
    public function __construct(
        public ?HistoryStatus $status = null,
        public ?string $messageClass = null,
        public ?string $transport = null,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromArray(array $query): self
    {
        return new self(
            HistoryStatus::tryFrom((string) ($query['status'] ?? '')),
            self::text($query, 'class'),
            self::text($query, 'transport'),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status?->value,
            'class' => $this->messageClass,
            'transport' => $this->transport,
        ], static fn (?string $value): bool => null !== $value);
    }

    public function apply(QueryBuilder $query): QueryBuilder
    {
        if (null !== $this->status) {
            $query->andWhere('status = :status')->setParameter('status', $this->status->value);
        }

        if (null !== $this->messageClass) {
            $query->andWhere('message_class LIKE :class')->setParameter('class', '%'.addcslashes($this->messageClass, '%_').'%');
        }

        if (null !== $this->transport) {
            $query->andWhere('transport = :transport')->setParameter('transport', $this->transport);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function text(array $query, string $key): ?string
    {
        $value = trim((string) ($query[$key] ?? ''));

        return '' === $value ? null : $value;
    }
}
