<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Integration;

use EasyAdminMessengerManager\Batch\BatchAction;
use EasyAdminMessengerManager\Batch\BatchDispatcher;
use EasyAdminMessengerManager\Batch\MessageSelection;
use EasyAdminMessengerManager\Failed\FailedMessageRemover;
use EasyAdminMessengerManager\Failed\FailedMessageRequeuer;
use EasyAdminMessengerManager\Failed\FailedMessageRetrier;
use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\MessageFinder;
use EasyAdminMessengerManager\Overview\QueueOverview;
use EasyAdminMessengerManager\Tests\App\TestMessage;
use EasyAdminMessengerManager\Transport\TransportException;
use EasyAdminMessengerManager\Transport\TransportRegistry;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;

final class FailedMessagesTest extends MessengerTestCase
{
    public function testRegistryKnowsTransportsAndFailureTransports(): void
    {
        $registry = self::getContainer()->get(TransportRegistry::class);

        self::assertSame(['async', 'failed', 'pending'], $registry->names());
        self::assertSame(['failed'], $registry->failureNames());
        self::assertTrue($registry->isListable('failed'));
        self::assertFalse($registry->isListable('async'));
    }

    public function testOverviewCountsMessagesPerTransport(): void
    {
        $this->addFailed(new TestMessage('one'));

        $summaries = self::getContainer()->get(QueueOverview::class)->summaries(['async', 'pending']);

        self::assertCount(1, $summaries);
        self::assertSame('failed', $summaries[0]->name);
        self::assertSame(1, $summaries[0]->count);
        self::assertTrue($summaries[0]->failure);
    }

    public function testFinderReadsFailureDetailsAndFilters(): void
    {
        $this->addFailed(new TestMessage('one'), 'async', 'Database is gone');
        $this->addFailed(new TestMessage('two'), 'other', 'Timeout');
        $all = $this->failedViews();
        $timeouts = $this->failedViews(MessageFilter::fromArray(['exception' => 'timeout']));
        $fromAsync = $this->failedViews(MessageFilter::fromArray(['origin' => 'async']));

        self::assertCount(2, $all);
        self::assertSame(TestMessage::class, $all[0]->messageClass);
        self::assertSame('TestMessage', $all[0]->shortClass());
        self::assertSame(\DomainException::class, $all[0]->exceptionClass);
        self::assertSame(2, $all[0]->retryCount);
        self::assertCount(1, $timeouts);
        self::assertSame('Timeout', $timeouts[0]->exceptionMessage);
        self::assertCount(1, $fromAsync);
    }

    public function testFindUnknownMessageThrows(): void
    {
        $this->expectException(TransportException::class);

        self::getContainer()->get(MessageFinder::class)->find('failed', '999');
    }

    public function testRemoverDeletesTheMessage(): void
    {
        $this->addFailed(new TestMessage('one'));
        $view = $this->onlyFailed();

        self::getContainer()->get(FailedMessageRemover::class)->remove('failed', $view->id);

        self::assertSame([], $this->failed());
    }

    public function testRemoverRefusesNonFailureTransports(): void
    {
        $this->expectException(TransportException::class);

        self::getContainer()->get(FailedMessageRemover::class)->remove('async', '1');
    }

    public function testRequeuerSendsTheMessageBackToItsOriginalTransport(): void
    {
        $this->addFailed(new TestMessage('one'));

        self::getContainer()->get(FailedMessageRequeuer::class)->requeue('failed', $this->onlyFailed()->id);

        self::assertSame([], $this->failed());
        $sent = $this->async()->getSent();
        self::assertCount(1, $sent);
        self::assertNull($sent[0]->last(SentToFailureTransportStamp::class));
        self::assertSame('one', $sent[0]->getMessage()->label);
    }

    public function testRetrySucceedsAndRemovesTheMessage(): void
    {
        $this->addFailed(new TestMessage('works'));

        $result = self::getContainer()->get(FailedMessageRetrier::class)->retry('failed', $this->onlyFailed()->id);

        self::assertTrue($result->succeeded);
        self::assertSame([], $this->failed());
    }

    public function testRetryFailureKeepsTheMessageAndReportsTheError(): void
    {
        $this->addFailed(new TestMessage('broken', true));

        $result = self::getContainer()->get(FailedMessageRetrier::class)->retry('failed', $this->onlyFailed()->id);

        self::assertFalse($result->succeeded);
        self::assertStringContainsString('Handler failed for broken', (string) $result->error);
        self::assertStringContainsString('Handler failed for broken', (string) $this->onlyFailed()->exceptionMessage);
    }

    public function testBatchRequeuesEveryMatchingMessage(): void
    {
        $this->addFailed(new TestMessage('one'));
        $this->addFailed(new TestMessage('two'));
        $this->addFailed(new TestMessage('three'), 'other');
        $matching = $this->failedViews(MessageFilter::fromArray(['origin' => 'async']));

        $count = self::getContainer()->get(BatchDispatcher::class)->dispatch(BatchAction::Requeue, MessageSelection::fromViews($matching));

        self::assertSame(2, $count);
        self::assertCount(2, $this->async()->getSent());
        self::assertCount(1, $this->failed());
    }

    public function testBatchRemovesSelectedReferencesAndIgnoresGarbage(): void
    {
        $this->addFailed(new TestMessage('one'));
        $reference = MessageSelection::reference($this->onlyFailed());

        $selection = MessageSelection::fromReferences([$reference, 'garbage', 'async::12']);
        self::getContainer()->get(BatchDispatcher::class)->dispatch(BatchAction::Remove, $selection);

        self::assertSame([], $this->failed());
    }

    /**
     * @return list<\EasyAdminMessengerManager\Message\MessageView>
     */
    private function failed(): array
    {
        return $this->failedViews();
    }

    private function onlyFailed(): \EasyAdminMessengerManager\Message\MessageView
    {
        $failed = $this->failed();
        self::assertCount(1, $failed);

        return $failed[0];
    }
}
