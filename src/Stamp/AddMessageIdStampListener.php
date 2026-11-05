<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Stamp;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;

final class AddMessageIdStampListener
{
    #[AsEventListener]
    public function __invoke(SendMessageToTransportsEvent $event): void
    {
        $envelope = $event->getEnvelope();
        if (null !== $envelope->last(EammMessageIdStamp::class)) {
            return;
        }

        $event->setEnvelope($envelope->with(EammMessageIdStamp::generate()));
    }
}
