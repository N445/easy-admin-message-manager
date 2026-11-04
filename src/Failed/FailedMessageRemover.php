<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Failed;

use EasyAdminMessengerManager\Message\MessageFinder;
use EasyAdminMessengerManager\Transport\TransportRegistry;

final readonly class FailedMessageRemover
{
    public function __construct(
        private TransportRegistry $transports,
        private MessageFinder $finder,
    ) {
    }

    public function remove(string $transport, string $id): void
    {
        $envelope = $this->finder->findEnvelope($transport, $id);
        $this->transports->failureReceiver($transport)->reject($envelope);
    }
}
