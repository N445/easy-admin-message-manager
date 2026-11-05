<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\History;

use Doctrine\DBAL\Query\QueryBuilder;
use EasyAdminMessengerManager\Message\DateInput;

final readonly class HistoryFilter
{
    public function __construct(
        public ?HistoryStatus $status = null,
        public ?string $messageClass = null,
        public ?string $transport = null,
        public ?string $exception = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public ?int $minDurationMs = null,
        public ?string $messageId = null,
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
            self::text($query, 'exception'),
            DateInput::start()->parse($query['from'] ?? null),
            DateInput::end()->parse($query['to'] ?? null),
            self::positiveInteger($query, 'duration'),
            self::text($query, 'message'),
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
            'exception' => $this->exception,
            'from' => DateInput::start()->format($this->from),
            'to' => DateInput::end()->format($this->to),
            'duration' => null === $this->minDurationMs ? null : (string) $this->minDurationMs,
            'message' => $this->messageId,
        ], static fn (?string $value): bool => null !== $value);
    }

    public function fromInput(): string
    {
        return DateInput::input($this->from);
    }

    public function toInput(): string
    {
        return DateInput::input($this->to);
    }

    public function apply(QueryBuilder $query): QueryBuilder
    {
        $this->equals($query, 'status', $this->status?->value);
        $this->equals($query, 'transport', $this->transport);
        $this->equals($query, 'message_id', $this->messageId);
        $this->contains($query, 'class', $this->messageClass, 'message_class');
        $this->contains($query, 'exception', $this->exception, 'error_class', 'error_message');
        $this->period($query);

        if (null !== $this->minDurationMs) {
            $query->andWhere('duration_ms >= :min_duration')->setParameter('min_duration', $this->minDurationMs);
        }

        return $query;
    }

    private function equals(QueryBuilder $query, string $column, ?string $value): void
    {
        if (null !== $value) {
            $query->andWhere(\sprintf('%1$s = :%1$s', $column))->setParameter($column, $value);
        }
    }

    private function contains(QueryBuilder $query, string $name, ?string $term, string ...$columns): void
    {
        if (null === $term) {
            return;
        }

        $conditions = array_map(static fn (string $column): string => \sprintf('%s LIKE :%s', $column, $name), $columns);
        $query->andWhere('('.implode(' OR ', $conditions).')')->setParameter($name, '%'.addcslashes($term, '%_').'%');
    }

    private function period(QueryBuilder $query): void
    {
        if (null !== $this->from) {
            $query->andWhere('handled_at >= :from')->setParameter('from', $this->from->format('Y-m-d H:i:s'));
        }

        if (null !== $this->to) {
            $query->andWhere('handled_at <= :to')->setParameter('to', $this->to->format('Y-m-d H:i:s'));
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function text(array $query, string $key): ?string
    {
        $value = trim((string) ($query[$key] ?? ''));

        return '' === $value ? null : $value;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function positiveInteger(array $query, string $key): ?int
    {
        $value = filter_var($query[$key] ?? null, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return false === $value ? null : $value;
    }
}
