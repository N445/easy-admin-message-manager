<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Message;

use EasyAdminMessengerManager\Transport\TransportRegistry;

final readonly class PendingMessageRemover
{
    public function __construct(
        private TransportRegistry $transports,
        private MessageFinder $finder,
    ) {
    }

    public function remove(string $transport, string $id): void
    {
        $this->transports->listable($transport)->reject($this->finder->findEnvelope($transport, $id));
    }
}
