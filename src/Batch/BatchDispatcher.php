<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Batch;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final readonly class BatchDispatcher
{
    public function __construct(
        private MessageBusInterface $bus,
        private int $chunkSize,
        private ?string $transport = null,
    ) {
    }

    public function dispatch(BatchAction $action, MessageSelection $selection): BatchReceipt
    {
        foreach ($selection->idsByTransport() as $transport => $ids) {
            $this->dispatchChunks($action, $transport, $ids);
        }

        return new BatchReceipt($action, $selection->count(), $this->transport);
    }

    /**
     * @param list<string> $ids
     */
    private function dispatchChunks(BatchAction $action, string $transport, array $ids): void
    {
        foreach (array_chunk($ids, $this->chunkSize) as $chunk) {
            $this->bus->dispatch($this->envelope(new FailedMessagesBatch($action, $transport, $chunk)));
        }
    }

    private function envelope(FailedMessagesBatch $batch): Envelope
    {
        if (null === $this->transport) {
            return new Envelope($batch);
        }

        return new Envelope($batch, [new TransportNamesStamp([$this->transport])]);
    }
}
