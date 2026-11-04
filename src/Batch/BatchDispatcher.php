<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Batch;

use Symfony\Component\Messenger\MessageBusInterface;

final readonly class BatchDispatcher
{
    public function __construct(
        private MessageBusInterface $bus,
        private int $chunkSize,
    ) {
    }

    public function dispatch(BatchAction $action, MessageSelection $selection): int
    {
        foreach ($selection->idsByTransport() as $transport => $ids) {
            $this->dispatchChunks($action, $transport, $ids);
        }

        return $selection->count();
    }

    /**
     * @param list<string> $ids
     */
    private function dispatchChunks(BatchAction $action, string $transport, array $ids): void
    {
        foreach (array_chunk($ids, $this->chunkSize) as $chunk) {
            $this->bus->dispatch(new FailedMessagesBatch($action, $transport, $chunk));
        }
    }
}
