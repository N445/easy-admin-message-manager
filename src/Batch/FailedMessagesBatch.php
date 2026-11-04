<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Batch;

final readonly class FailedMessagesBatch
{
    /**
     * @param list<string> $ids
     */
    public function __construct(
        public BatchAction $action,
        public string $transport,
        public array $ids,
    ) {
    }
}
