<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Message;

enum MessageOrder: string
{
    case NewestFirst = 'desc';
    case OldestFirst = 'asc';

    public static function fromQuery(string $value): self
    {
        return self::tryFrom($value) ?? self::NewestFirst;
    }

    public function reversed(): self
    {
        return self::NewestFirst === $this ? self::OldestFirst : self::NewestFirst;
    }

    public function sql(): string
    {
        return strtoupper($this->value);
    }

    /**
     * @template T
     *
     * @param list<T> $newestFirst
     *
     * @return list<T>
     */
    public function arrange(array $newestFirst): array
    {
        return self::NewestFirst === $this ? $newestFirst : array_reverse($newestFirst);
    }
}
