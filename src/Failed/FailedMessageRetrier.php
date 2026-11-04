<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Failed;

use EasyAdminMessengerManager\Message\MessageFinder;
use EasyAdminMessengerManager\Transport\TransportRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\SingleMessageReceiver;
use Symfony\Component\Messenger\Worker;

final readonly class FailedMessageRetrier
{
    public function __construct(
        private TransportRegistry $transports,
        private MessageFinder $finder,
        private MessageBusInterface $bus,
        private EventDispatcherInterface $dispatcher,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function retry(string $transport, string $id): ActionResult
    {
        $receiver = new SingleMessageReceiver($this->transports->failureReceiver($transport), $this->finder->findEnvelope($transport, $id));
        $stopAfterOne = new StopWorkerOnMessageLimitListener(1);
        $outcome = new RetryOutcomeListener($this->transports->sender($transport));
        $this->dispatcher->addSubscriber($stopAfterOne);
        $this->dispatcher->addSubscriber($outcome);

        try {
            (new Worker([$transport => $receiver], $this->bus, $this->dispatcher, $this->logger))->run();
        } finally {
            $this->dispatcher->removeSubscriber($stopAfterOne);
            $this->dispatcher->removeSubscriber($outcome);
        }

        return $outcome->result();
    }
}
