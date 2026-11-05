<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Unit;

use EasyAdminMessengerManager\Batch\BatchAction;
use EasyAdminMessengerManager\Batch\BatchDispatcher;
use EasyAdminMessengerManager\Batch\MessageSelection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class BatchDispatcherTest extends TestCase
{
    public function testChunksAreSentToTheConfiguredTransport(): void
    {
        $bus = $this->recordingBus();

        $receipt = (new BatchDispatcher($bus, 2, 'async'))->dispatch(BatchAction::Requeue, MessageSelection::fromReferences(['failed::1', 'failed::2', 'failed::3']));

        self::assertSame(3, $receipt->count);
        self::assertSame('async', $receipt->transport);
        self::assertCount(2, $bus->envelopes);
        self::assertSame(['async'], $bus->envelopes[0]->last(TransportNamesStamp::class)?->getTransportNames());
    }

    public function testChunksAreHandledByTheBusRoutingWithoutTransport(): void
    {
        $bus = $this->recordingBus();

        (new BatchDispatcher($bus, 200))->dispatch(BatchAction::Remove, MessageSelection::fromReferences(['failed::1']));

        self::assertNull($bus->envelopes[0]->last(TransportNamesStamp::class));
    }

    private function recordingBus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            /** @var list<Envelope> */
            public array $envelopes = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = Envelope::wrap($message, $stamps);
                $this->envelopes[] = $envelope;

                return $envelope;
            }
        };
    }
}
