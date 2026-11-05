<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Message\Source;

use EasyAdminMessengerManager\Batch\MessageSelection;
use EasyAdminMessengerManager\Controller\Pagination;
use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\MessageOrder;
use EasyAdminMessengerManager\Message\MessagePage;
use EasyAdminMessengerManager\Message\MessageView;

final readonly class MessagePages
{
    /**
     * @param array<string, MessageSource> $sources
     */
    public function __construct(
        private array $sources,
        private int $sampleSize,
    ) {
    }

    public function count(MessageFilter $filter): int
    {
        return array_sum(array_map(static fn (MessageSource $source): int => $source->count($filter), $this->sources));
    }

    public function countUpTo(MessageFilter $filter, int $limit): int
    {
        $count = 0;
        foreach ($this->sources as $source) {
            $count += $source->countUpTo($filter, max(0, $limit - $count));
        }

        return $count;
    }

    public function page(MessageFilter $filter, int $requestedPage, int $perPage, MessageOrder $order = MessageOrder::NewestFirst): MessagePage
    {
        $pagination = Pagination::of($this->count($filter), $requestedPage, $perPage);

        return new MessagePage($this->slice($filter, $pagination->offset(), $perPage, $order), $pagination, $this->isComplete());
    }

    public function first(MessageFilter $filter): ?MessageView
    {
        return $this->slice($filter, 0, 1)[0] ?? null;
    }

    /**
     * @return list<MessageView>
     */
    public function sample(MessageFilter $filter): array
    {
        return $this->slice($filter, 0, $this->sampleSize);
    }

    public function sampleSize(): int
    {
        return $this->sampleSize;
    }

    public function selection(MessageFilter $filter): MessageSelection
    {
        $selection = new MessageSelection();
        foreach ($this->sources as $transport => $source) {
            $selection = $selection->with($transport, $source->ids($filter));
        }

        return $selection;
    }

    public function remove(MessageFilter $filter): int
    {
        return array_sum(array_map(static fn (MessageSource $source): int => $source->remove($filter), $this->sources));
    }

    public function oldestQueuedAt(): ?\DateTimeImmutable
    {
        $dates = array_filter(array_map(static fn (MessageSource $source): ?\DateTimeImmutable => $source->oldestQueuedAt(), $this->sources));

        return [] === $dates ? null : min($dates);
    }

    public function isComplete(): bool
    {
        return [] === array_filter($this->sources, static fn (MessageSource $source): bool => !$source->isComplete());
    }

    /**
     * @return list<MessageView>
     */
    private function slice(MessageFilter $filter, int $offset, int $limit, MessageOrder $order = MessageOrder::NewestFirst): array
    {
        if (1 === \count($this->sources)) {
            return array_values($this->sources)[0]->slice($filter, $offset, $limit, $order);
        }

        $views = [];
        foreach ($order->arrange(array_values($this->sources)) as $source) {
            $count = $source->count($filter);
            $views = [...$views, ...$this->sliceOf($source, $filter, max(0, $offset), $limit - \count($views), $order)];
            $offset -= $count;
        }

        return $views;
    }

    /**
     * @return list<MessageView>
     */
    private function sliceOf(MessageSource $source, MessageFilter $filter, int $offset, int $limit, MessageOrder $order): array
    {
        if ($limit <= 0) {
            return [];
        }

        return $source->slice($filter, $offset, $limit, $order);
    }
}
