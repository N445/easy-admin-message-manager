<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Unit;

use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\MessageView;
use EasyAdminMessengerManager\Stamp\EammMessageIdStamp;
use EasyAdminMessengerManager\Tests\App\TestMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

final class MessageViewTest extends TestCase
{
    public function testRedisStreamIdsGiveTheQueuedDate(): void
    {
        $view = MessageView::fromEnvelope((new Envelope(new TestMessage('x')))->with(new TransportMessageIdStamp('1767225600123-4')), 'async');

        self::assertSame(1767225600, $view->queuedAt?->getTimestamp());
        self::assertSame(date_default_timezone_get(), $view->queuedAt?->getTimezone()->getName());
    }

    public function testOtherIdsHaveNoQueuedDateUnlessGiven(): void
    {
        $envelope = (new Envelope(new TestMessage('x')))->with(new TransportMessageIdStamp(42));
        $queuedAt = new \DateTimeImmutable('2026-01-01 10:00:00');

        self::assertNull(MessageView::fromEnvelope($envelope, 'pending')->queuedAt);
        self::assertSame($queuedAt, MessageView::fromEnvelope($envelope, 'pending', $queuedAt)->queuedAt);
    }

    public function testTrackingIdComesFromTheStamp(): void
    {
        $stamp = EammMessageIdStamp::generate();
        $view = MessageView::fromEnvelope(new Envelope(new TestMessage('x'), [$stamp]), 'async');

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-/', $stamp->id);
        self::assertSame($stamp->id, $view->trackingId());
        self::assertNull(MessageView::fromEnvelope(new Envelope(new TestMessage('x')), 'async')->trackingId());
    }

    public function testPendingMessagesAreFilteredOnTheirQueuedDate(): void
    {
        $view = MessageView::fromEnvelope((new Envelope(new TestMessage('x')))->with(new TransportMessageIdStamp('1767225600000-0')), 'async');
        $filter = new MessageFilter(from: new \DateTimeImmutable('@1767225000'), to: new \DateTimeImmutable('@1767226000'));

        self::assertTrue($filter->matches($view, true));
        self::assertFalse($filter->matches($view));
    }

    public function testTrackingIdFilter(): void
    {
        $stamp = new EammMessageIdStamp('0190a6f0-0000-7000-8000-000000000001');
        $view = MessageView::fromEnvelope(new Envelope(new TestMessage('x'), [$stamp]), 'failed');

        self::assertTrue(MessageFilter::fromArray(['tracking' => $stamp->id])->matches($view));
        self::assertFalse(MessageFilter::fromArray(['tracking' => 'other'])->matches($view));
        self::assertSame(['tracking' => $stamp->id], MessageFilter::fromArray(['tracking' => $stamp->id])->toArray());
    }
}
