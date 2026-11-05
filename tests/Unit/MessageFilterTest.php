<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Unit;

use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\MessageView;
use EasyAdminMessengerManager\Tests\App\TestMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

final class MessageFilterTest extends TestCase
{
    public function testEmptyFilterMatchesEverything(): void
    {
        $filter = MessageFilter::fromArray(['class' => '  ', 'from' => 'not-a-date']);

        self::assertTrue($filter->isEmpty());
        self::assertTrue($filter->matches($this->view()));
    }

    public function testCriteriaAreCaseInsensitiveSubstrings(): void
    {
        self::assertTrue(MessageFilter::fromArray(['class' => 'testmess'])->matches($this->view()));
        self::assertTrue(MessageFilter::fromArray(['exception' => 'gone'])->matches($this->view()));
        self::assertFalse(MessageFilter::fromArray(['exception' => 'timeout'])->matches($this->view()));
        self::assertFalse(MessageFilter::fromArray(['origin' => 'async_low'])->matches($this->view()));
    }

    public function testPeriodIncludesWholeDays(): void
    {
        $view = $this->view(new \DateTimeImmutable('2026-03-10 23:30:00'));

        self::assertTrue(MessageFilter::fromArray(['from' => '2026-03-10', 'to' => '2026-03-10'])->matches($view));
        self::assertFalse(MessageFilter::fromArray(['from' => '2026-03-11'])->matches($view));
        self::assertFalse(MessageFilter::fromArray(['to' => '2026-03-09'])->matches($view));
    }

    public function testPeriodAcceptsMinutePrecision(): void
    {
        $view = $this->view(new \DateTimeImmutable('2026-03-10 14:30:20'));

        self::assertTrue(MessageFilter::fromArray(['from' => '2026-03-10T14:30', 'to' => '2026-03-10T14:30'])->matches($view));
        self::assertFalse(MessageFilter::fromArray(['from' => '2026-03-10T14:31'])->matches($view));
        self::assertFalse(MessageFilter::fromArray(['to' => '2026-03-10T14:29'])->matches($view));
        self::assertTrue(MessageFilter::fromArray(['from' => '2026-02-31T10:00'])->isEmpty());
    }

    public function testInputValuesUseTheDatetimeLocalFormat(): void
    {
        $filter = MessageFilter::fromArray(['from' => '2026-03-10', 'to' => '2026-03-11T08:15']);

        self::assertSame('2026-03-10T00:00', $filter->fromInput());
        self::assertSame('2026-03-11T08:15', $filter->toInput());
        self::assertSame(['from' => '2026-03-10', 'to' => '2026-03-11T08:15'], $filter->toArray());
        self::assertSame('', (new MessageFilter())->fromInput());
    }

    public function testToArrayRoundTrips(): void
    {
        $query = ['class' => 'Foo', 'origin' => 'async', 'exception' => 'Bar', 'from' => '2026-01-01', 'to' => '2026-01-31'];

        self::assertSame($query, MessageFilter::fromArray($query)->toArray());
    }

    private function view(?\DateTimeImmutable $failedAt = null): MessageView
    {
        $envelope = (new Envelope(new TestMessage('x')))->with(
            new TransportMessageIdStamp(7),
            new SentToFailureTransportStamp('async'),
            ErrorDetailsStamp::create(new \RuntimeException('Database is gone')),
            new RedeliveryStamp(1, $failedAt ?? new \DateTimeImmutable()),
        );

        return MessageView::fromEnvelope($envelope, 'failed');
    }
}
