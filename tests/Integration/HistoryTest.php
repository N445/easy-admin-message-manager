<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Integration;

use EasyAdminMessengerManager\History\HistoryEntry;
use EasyAdminMessengerManager\History\HistoryFilter;
use EasyAdminMessengerManager\History\HistoryRepository;
use EasyAdminMessengerManager\History\HistoryStatus;
use EasyAdminMessengerManager\Tests\App\TestMessage;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;

final class HistoryTest extends MessengerTestCase
{
    public function testHandledAndFailedMessagesAreRecorded(): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new TestMessage('ok'));
        $bus->dispatch(new TestMessage('ko', true));

        $this->consume(2);

        $history = self::getContainer()->get(HistoryRepository::class);
        self::assertSame(2, $history->count(new HistoryFilter()));
        $failed = $history->page(new HistoryFilter(HistoryStatus::Failed), 1, 10);
        self::assertCount(1, $failed);
        self::assertSame(\DomainException::class, $failed[0]['error_class']);
        self::assertSame('Handler failed for ko', $failed[0]['error_message']);
        self::assertArrayNotHasKey('payload', $failed[0]);
        $payload = (string) $history->find((int) $failed[0]['id'])['payload'];
        self::assertStringContainsString('"label": "ko"', $payload);
        self::assertStringNotContainsString('p4ss', $payload);
        self::assertStringNotContainsString('hidden-value', $payload);
        self::assertSame(['failed' => 1, 'handled' => 1], $history->statusCounts(new \DateTimeImmutable('-1 hour')));
    }

    public function testPurgeCommandHonoursAgeStatusAndDryRun(): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new TestMessage('ok'));
        $bus->dispatch(new TestMessage('ko', true));
        $this->consume(2);
        $history = self::getContainer()->get(HistoryRepository::class);
        $tester = new CommandTester((new Application(self::$kernel))->find('eamm:history:purge'));

        $tester->execute(['--older-than' => '1']);
        self::assertSame(2, $history->count(new HistoryFilter()));

        self::assertSame(1, $history->purge(new HistoryFilter(HistoryStatus::Failed), new \DateTimeImmutable('+1 minute'), true));
        self::assertSame(2, $history->count(new HistoryFilter()));
        self::assertSame(1, $history->purge(new HistoryFilter(HistoryStatus::Failed), new \DateTimeImmutable('+1 minute'), false));
        self::assertSame(1, $history->count(new HistoryFilter()));

        self::assertSame(Command::INVALID, $tester->execute(['--status' => 'unknown']));
    }

    public function testEntriesKeepPayloadAndTrace(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new TestMessage('ko', true));
        $this->consume(1);
        $history = self::getContainer()->get(HistoryRepository::class);

        $entry = $history->find((int) $history->page(new HistoryFilter(), 1, 1)[0]['id']);

        self::assertSame('ko', json_decode((string) $entry['payload'], true)['label']);
        self::assertStringContainsString('TestMessageHandler', (string) $entry['error_trace']);
    }

    public function testFiltersOnErrorPeriodDurationAndTrackingId(): void
    {
        $history = self::getContainer()->get(HistoryRepository::class);
        $history->insert(new HistoryEntry(TestMessage::class, 'async', HistoryStatus::Failed, 1, 1200, null, new \RuntimeException('Album 12 timed out'), null, 'tracked'));
        $history->insert(new HistoryEntry(TestMessage::class, 'async', HistoryStatus::Handled, 1, 30, null, null, null, 'other'));

        self::assertSame(1, $history->count(HistoryFilter::fromArray(['exception' => 'timed out'])));
        self::assertSame(1, $history->count(HistoryFilter::fromArray(['exception' => 'RuntimeException'])));
        self::assertSame(1, $history->count(HistoryFilter::fromArray(['duration' => '1000'])));
        self::assertSame(1, $history->count(HistoryFilter::fromArray(['message' => 'tracked'])));
        self::assertSame(2, $history->count(HistoryFilter::fromArray(['from' => date('Y-m-d\TH:i', time() - 120)])));
        self::assertSame(0, $history->count(HistoryFilter::fromArray(['to' => date('Y-m-d\TH:i', time() - 120)])));
    }

    private function consume(int $limit): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $stop = new StopWorkerOnMessageLimitListener($limit);
        $dispatcher->addSubscriber($stop);
        $worker = new Worker(['async' => $this->async()], self::getContainer()->get('messenger.routable_message_bus'), $dispatcher);
        $worker->run(['sleep' => 0]);
        $dispatcher->removeSubscriber($stop);
    }
}
