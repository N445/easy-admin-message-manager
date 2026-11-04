<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Message\Source;

use EasyAdminMessengerManager\Batch\MessageSelection;
use EasyAdminMessengerManager\Controller\Pagination;
use EasyAdminMessengerManager\Message\MessageFilter;
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

    public function page(MessageFilter $filter, int $requestedPage, int $perPage): MessagePage
    {
        $pagination = Pagination::of($this->count($filter), $requestedPage, $perPage);

        return new MessagePage($this->slice($filter, $pagination->offset(), $perPage), $pagination, $this->isComplete());
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

    public function isComplete(): bool
    {
        return [] === array_filter($this->sources, static fn (MessageSource $source): bool => !$source->isComplete());
    }

    /**
     * @return list<MessageView>
     */
    private function slice(MessageFilter $filter, int $offset, int $limit): array
    {
        if (1 === \count($this->sources)) {
            return array_values($this->sources)[0]->slice($filter, $offset, $limit);
        }

        $views = [];
        foreach ($this->sources as $source) {
            $count = $source->count($filter);
            $views = [...$views, ...$this->sliceOf($source, $filter, max(0, $offset), $limit - \count($views))];
            $offset -= $count;
        }

        return $views;
    }

    /**
     * @return list<MessageView>
     */
    private function sliceOf(MessageSource $source, MessageFilter $filter, int $offset, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return $source->slice($filter, $offset, $limit);
    }
}
