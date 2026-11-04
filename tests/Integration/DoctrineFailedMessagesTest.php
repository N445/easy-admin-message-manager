<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Integration;

use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\Source\MessagePages;
use EasyAdminMessengerManager\Message\Source\MessageSources;
use EasyAdminMessengerManager\Tests\App\TestMessage;
use EasyAdminMessengerManager\Transport\TransportException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Bundle\FrameworkBundle\Console\Application;

final class DoctrineFailedMessagesTest extends MessengerTestCase
{
    public function testCountsAndPagesNewestFirst(): void
    {
        foreach (range(1, 7) as $number) {
            $this->addFailed(new TestMessage('message-'.$number));
        }

        $page = $this->failed()->page(new MessageFilter(), 2, 3);

        self::assertTrue($page->complete);
        self::assertSame(7, $page->pagination->total);
        self::assertSame(3, $page->pagination->pages);
        self::assertSame(['message-4', 'message-3', 'message-2'], array_map(static fn ($view): string => $view->envelope->getMessage()->label, $page->views));
        self::assertNotSame('', $page->views[0]->id);
    }

    public function testFiltersInSql(): void
    {
        $this->addFailed(new TestMessage('one'), 'async', 'Database is gone');
        $this->addFailed(new TestMessage('two'), 'async_low', 'Timeout 100% reached');
        $this->addFailed(new TestMessage('three'), 'async_low', 'Database is gone');

        self::assertSame(2, $this->failed()->count(MessageFilter::fromArray(['exception' => 'database IS'])));
        self::assertSame(1, $this->failed()->count(MessageFilter::fromArray(['exception' => '100%'])));
        self::assertSame(2, $this->failed()->count(MessageFilter::fromArray(['origin' => 'async_low'])));
        self::assertSame(1, $this->failed()->count(MessageFilter::fromArray(['origin' => 'async'])));
        self::assertSame(3, $this->failed()->count(MessageFilter::fromArray(['class' => 'Tests\App\TestMessage'])));
        self::assertSame(0, $this->failed()->count(MessageFilter::fromArray(['class' => 'Unknown\Message'])));
        self::assertSame(3, $this->failed()->count(MessageFilter::fromArray(['from' => date('Y-m-d'), 'to' => date('Y-m-d')])));
        self::assertSame(0, $this->failed()->count(MessageFilter::fromArray(['to' => '2000-01-01'])));
    }

    public function testSelectionCoversEveryMatchingMessage(): void
    {
        $this->addFailed(new TestMessage('one'), 'async', 'Timeout');
        $this->addFailed(new TestMessage('two'), 'async', 'Timeout');
        $this->addFailed(new TestMessage('three'), 'async', 'Other');

        $selection = $this->failed()->selection(MessageFilter::fromArray(['exception' => 'timeout']));

        self::assertSame(2, $selection->count());
        self::assertSame(['failed'], array_keys($selection->idsByTransport()));
    }

    public function testSampleIsLimitedToTheMostRecentMessages(): void
    {
        $this->addFailed(new TestMessage('old'));
        $this->addFailed(new TestMessage('new'));

        self::assertSame('new', $this->failed()->sample(new MessageFilter())[0]->envelope->getMessage()->label);
    }

    public function testPendingDoctrineTransportsArePagedToo(): void
    {
        foreach (range(1, 4) as $number) {
            $this->transport('pending')->send(new \Symfony\Component\Messenger\Envelope(new TestMessage('pending-'.$number)));
        }

        $page = self::getContainer()->get(MessageSources::class)->transport('pending')->page(new MessageFilter(), 1, 3);

        self::assertSame(4, $page->pagination->total);
        self::assertCount(3, $page->views);
        self::assertSame('pending', $page->views[0]->transport);
    }

    public function testNonListableTransportsAreRejected(): void
    {
        $this->expectException(TransportException::class);

        self::getContainer()->get(MessageSources::class)->transport('async');
    }

    public function testRemoveDeletesOnlyMatchingMessages(): void
    {
        $this->addFailed(new TestMessage('one'), 'async', 'Timeout');
        $this->addFailed(new TestMessage('two'), 'async', 'Other');

        self::assertSame(1, $this->failed()->remove(MessageFilter::fromArray(['exception' => 'timeout'])));
        self::assertSame(1, $this->failed()->count(new MessageFilter()));
    }

    public function testPurgeCommand(): void
    {
        $this->addFailed(new TestMessage('one'), 'async', 'Timeout');
        $this->addFailed(new TestMessage('two'), 'async_low', 'Timeout');
        $tester = new CommandTester((new Application(self::$kernel))->find('eamm:failed:purge'));

        $tester->execute(['--origin' => 'async_low', '--dry-run' => true]);
        self::assertStringContainsString('1 failed messages match', $tester->getDisplay());
        self::assertSame(2, $this->failed()->count(new MessageFilter()));

        $tester->execute(['--older-than' => '1', '--force' => true]);
        self::assertStringContainsString('0 failed messages match', $tester->getDisplay());

        $tester->execute(['--origin' => 'async_low', '--force' => true]);
        self::assertStringContainsString('1 failed messages deleted', $tester->getDisplay());
        self::assertSame(1, $this->failed()->count(new MessageFilter()));
    }

    private function failed(): MessagePages
    {
        return self::getContainer()->get(MessageSources::class)->failed();
    }
}
