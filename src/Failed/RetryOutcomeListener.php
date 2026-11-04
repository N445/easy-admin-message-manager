<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Failed;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

final class RetryOutcomeListener implements EventSubscriberInterface
{
    private ActionResult $result;

    public function __construct(private readonly SenderInterface $failureSender)
    {
        $this->result = ActionResult::failure(new \RuntimeException('The message was not handled.'));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageHandledEvent::class => ['onHandled', -1024],
            WorkerMessageFailedEvent::class => ['onFailed', -1024],
        ];
    }

    public function onHandled(): void
    {
        $this->result = ActionResult::success();
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $this->result = ActionResult::failure($event->getThrowable());
        if ($event->willRetry()) {
            return;
        }

        $this->failureSender->send($event->getEnvelope()->withoutAll(TransportMessageIdStamp::class)->withoutAll(DelayStamp::class));
    }

    public function result(): ActionResult
    {
        return $this->result;
    }
}
