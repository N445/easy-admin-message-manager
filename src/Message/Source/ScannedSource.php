<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Message\Source;

use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\MessageFinder;
use EasyAdminMessengerManager\Message\MessageOrder;
use EasyAdminMessengerManager\Message\MessageView;
use EasyAdminMessengerManager\Transport\TransportRegistry;

final class ScannedSource implements MessageSource
{
    /** @var array<string, list<MessageView>> */
    private array $matching = [];

    private bool $complete = true;

    public function __construct(
        private readonly MessageFinder $finder,
        private readonly TransportRegistry $transports,
        private readonly string $transport,
        private readonly int $maxScan,
    ) {
    }

    public function count(MessageFilter $filter): int
    {
        return \count($this->matching($filter));
    }

    public function countUpTo(MessageFilter $filter, int $limit): int
    {
        return min($limit, $this->count($filter));
    }

    public function slice(MessageFilter $filter, int $offset, int $limit, MessageOrder $order = MessageOrder::NewestFirst): array
    {
        return \array_slice($order->arrange($this->matching($filter)), $offset, $limit);
    }

    public function ids(MessageFilter $filter): array
    {
        return array_map(static fn (MessageView $view): string => $view->id, $this->matching($filter));
    }

    public function remove(MessageFilter $filter): int
    {
        $receiver = $this->transports->listable($this->transport);
        $views = $this->matching($filter);
        foreach ($views as $view) {
            $receiver->reject($view->envelope);
        }

        $this->matching = [];

        return \count($views);
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function oldestQueuedAt(): ?\DateTimeImmutable
    {
        return ($this->finder->scan($this->transport, 1)[0] ?? null)?->queuedAt;
    }

    /**
     * @return list<MessageView>
     */
    private function matching(MessageFilter $filter): array
    {
        $key = serialize($filter->toArray());
        if (isset($this->matching[$key])) {
            return $this->matching[$key];
        }

        $views = $this->finder->scan($this->transport, $this->maxScan);
        $this->complete = \count($views) < $this->maxScan;

        $byQueuedAt = !$this->transports->isFailure($this->transport);

        return $this->matching[$key] = array_values(array_filter($views, static fn (MessageView $view): bool => $filter->matches($view, $byQueuedAt)));
    }
}
