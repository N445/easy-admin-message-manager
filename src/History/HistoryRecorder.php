<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\History;

use EasyAdminMessengerManager\Payload\PayloadNormalizer;
use EasyAdminMessengerManager\Stamp\EammMessageIdStamp;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

final class HistoryRecorder implements EventSubscriberInterface
{
    /** @var \WeakMap<object, array{float, int}> */
    private \WeakMap $starts;

    /**
     * @param list<string> $excludedClasses
     */
    public function __construct(
        private readonly HistoryRepository $repository,
        private readonly PayloadNormalizer $payloadNormalizer,
        private readonly array $excludedClasses = [],
        private readonly bool $capturePayload = false,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->starts = new \WeakMap();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => ['onReceived', -256],
            WorkerMessageHandledEvent::class => ['onHandled', -256],
            WorkerMessageFailedEvent::class => ['onFailed', -256],
        ];
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->starts[$event->getEnvelope()->getMessage()] = [microtime(true), memory_get_usage()];
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $this->record($event->getEnvelope(), $event->getReceiverName(), HistoryStatus::Handled, null);
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $status = $event->willRetry() ? HistoryStatus::Retrying : HistoryStatus::Failed;
        $this->record($event->getEnvelope(), $event->getReceiverName(), $status, $event->getThrowable());
    }

    private function record(Envelope $envelope, string $transport, HistoryStatus $status, ?\Throwable $error): void
    {
        $message = $envelope->getMessage();
        if ($this->isExcluded($message)) {
            return;
        }

        try {
            $this->repository->insert($this->entry($envelope, $transport, $status, $error));
        } catch (\Throwable $exception) {
            $this->logger->warning('Messenger history could not be recorded: {error}', ['error' => $exception->getMessage()]);
        }
    }

    private function entry(Envelope $envelope, string $transport, HistoryStatus $status, ?\Throwable $error): HistoryEntry
    {
        $message = $envelope->getMessage();
        [$startedAt, $memory] = $this->starts[$message] ?? [null, null];
        unset($this->starts[$message]);

        return new HistoryEntry(
            $message::class,
            $transport,
            $status,
            ($envelope->last(RedeliveryStamp::class)?->getRetryCount() ?? 0) + 1,
            null === $startedAt ? null : (int) round((microtime(true) - $startedAt) * 1000),
            null === $memory ? null : max(0, memory_get_peak_usage() - $memory),
            $error,
            $this->capturePayload ? $this->payloadNormalizer->json($message) : null,
            $envelope->last(EammMessageIdStamp::class)?->id,
        );
    }

    private function isExcluded(object $message): bool
    {
        foreach ($this->excludedClasses as $excludedClass) {
            if ($message instanceof $excludedClass) {
                return true;
            }
        }

        return false;
    }
}
