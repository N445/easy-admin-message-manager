<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Unit;

use EasyAdminMessengerManager\History\HistoryFilter;
use EasyAdminMessengerManager\History\HistoryStatus;
use PHPUnit\Framework\TestCase;

final class HistoryFilterTest extends TestCase
{
    public function testQueryRoundTrips(): void
    {
        $query = [
            'status' => 'failed',
            'class' => 'Album',
            'transport' => 'async',
            'exception' => 'timeout',
            'from' => '2026-01-01T10:30',
            'to' => '2026-01-31',
            'duration' => '250',
            'message' => '0190a6f0-0000-7000-8000-000000000001',
        ];

        $filter = HistoryFilter::fromArray($query);

        self::assertSame($query, $filter->toArray());
        self::assertSame(HistoryStatus::Failed, $filter->status);
        self::assertSame('2026-01-31T23:59', $filter->toInput());
    }

    public function testInvalidValuesAreIgnored(): void
    {
        $filter = HistoryFilter::fromArray(['status' => 'nope', 'duration' => '-5', 'from' => 'yesterday', 'message' => '  ']);

        self::assertSame([], $filter->toArray());
    }
}
