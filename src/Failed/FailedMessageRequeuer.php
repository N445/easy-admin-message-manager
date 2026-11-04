<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Failed;

use EasyAdminMessengerManager\Message\MessageFinder;
use EasyAdminMessengerManager\Transport\TransportException;
use EasyAdminMessengerManager\Transport\TransportRegistry;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

final readonly class FailedMessageRequeuer
{
    private const array FAILURE_STAMPS = [
        SentToFailureTransportStamp::class,
        RedeliveryStamp::class,
        ErrorDetailsStamp::class,
        DelayStamp::class,
        TransportMessageIdStamp::class,
    ];

    public function __construct(
        private TransportRegistry $transports,
        private MessageFinder $finder,
    ) {
    }

    public function requeue(string $transport, string $id): void
    {
        $envelope = $this->finder->findEnvelope($transport, $id);
        $original = $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName()
            ?? throw TransportException::noOriginalTransport($id);

        $this->transports->sender($original)->send($this->withoutFailureStamps($envelope));
        $this->transports->failureReceiver($transport)->reject($envelope);
    }

    private function withoutFailureStamps(Envelope $envelope): Envelope
    {
        foreach (self::FAILURE_STAMPS as $stampClass) {
            $envelope = $envelope->withoutAll($stampClass);
        }

        return $envelope;
    }
}
