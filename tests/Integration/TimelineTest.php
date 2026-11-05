<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Integration;

use EasyAdminMessengerManager\Failed\FailedMessageRequeuer;
use EasyAdminMessengerManager\History\HistoryFilter;
use EasyAdminMessengerManager\History\HistoryRepository;
use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\Source\MessageSources;
use EasyAdminMessengerManager\Stamp\EammMessageIdStamp;
use EasyAdminMessengerManager\Tests\App\TestMessage;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

final class TimelineTest extends MessengerTestCase
{
    public function testDispatchedMessagesGetATrackingIdThatSurvivesFailureAndRequeue(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new TestMessage('ko', true));
        $trackingId = $this->async()->getSent()[0]->last(EammMessageIdStamp::class)?->id;
        self::assertNotNull($trackingId);

        $this->consumeOne();

        $failed = $this->failedViews()[0];
        self::assertSame($trackingId, $failed->trackingId());
        $history = self::getContainer()->get(HistoryRepository::class);
        self::assertSame([$trackingId], array_column($history->timeline($trackingId, 10), 'message_id'));
        self::assertSame(1, $history->count(new HistoryFilter(messageId: $trackingId)));

        self::getContainer()->get(FailedMessageRequeuer::class)->requeue('failed', $failed->id);

        self::assertSame($trackingId, $this->async()->getSent()[1]->last(EammMessageIdStamp::class)?->id);
    }

    public function testAnExistingTrackingIdIsKept(): void
    {
        $stamp = new EammMessageIdStamp('0190a6f0-0000-7000-8000-000000000001');

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new TestMessage('ok'), [$stamp]);

        self::assertSame([$stamp], $this->async()->getSent()[0]->all(EammMessageIdStamp::class));
    }

    public function testTheStampSurvivesPhpSerialization(): void
    {
        $serializer = new PhpSerializer();
        $stamp = EammMessageIdStamp::generate();

        $decoded = $serializer->decode($serializer->encode(new Envelope(new TestMessage('x'), [$stamp])));

        self::assertSame($stamp->id, $decoded->last(EammMessageIdStamp::class)?->id);
    }

    public function testFailedMessagesCanBeFoundByTrackingId(): void
    {
        $stamp = new EammMessageIdStamp('0190a6f0-0000-7000-8000-000000000002');
        $this->transport('failed')->send(new Envelope(new TestMessage('tracked'), [$stamp]));
        $this->addFailed(new TestMessage('other'));

        $found = self::getContainer()->get(MessageSources::class)->failed()
            ->first(new MessageFilter(trackingId: $stamp->id));

        self::assertSame('tracked', $found?->envelope->getMessage()->label);
    }

    private function consumeOne(): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $stop = new StopWorkerOnMessageLimitListener(1);
        $dispatcher->addSubscriber($stop);
        (new Worker(['async' => $this->async()], self::getContainer()->get('messenger.routable_message_bus'), $dispatcher))->run(['sleep' => 0]);
        $dispatcher->removeSubscriber($stop);
    }
}
