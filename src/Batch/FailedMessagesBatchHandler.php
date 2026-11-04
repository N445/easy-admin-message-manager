<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Batch;

use EasyAdminMessengerManager\Failed\FailedMessageRemover;
use EasyAdminMessengerManager\Failed\FailedMessageRequeuer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class FailedMessagesBatchHandler
{
    public function __construct(
        private FailedMessageRequeuer $requeuer,
        private FailedMessageRemover $remover,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(FailedMessagesBatch $batch): void
    {
        foreach ($batch->ids as $id) {
            $this->process($batch->action, $batch->transport, $id);
        }
    }

    private function process(BatchAction $action, string $transport, string $id): void
    {
        try {
            match ($action) {
                BatchAction::Requeue => $this->requeuer->requeue($transport, $id),
                BatchAction::Remove => $this->remover->remove($transport, $id),
            };
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed message batch {action} skipped message {id}: {error}', [
                'action' => $action->value,
                'id' => $id,
                'transport' => $transport,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
