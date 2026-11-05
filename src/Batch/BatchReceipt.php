<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Batch;

final readonly class BatchReceipt
{
    public function __construct(
        public BatchAction $action,
        public int $count,
        public ?string $transport,
    ) {
    }

    public function isQueued(): bool
    {
        return null !== $this->transport;
    }
}
