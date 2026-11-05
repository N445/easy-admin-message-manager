<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Unit;

use EasyAdminMessengerManager\Controller\Pagination;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    public function testClampsTheRequestedPage(): void
    {
        self::assertSame(3, Pagination::of(120, 99, 50)->page);
        self::assertSame(1, Pagination::of(0, -4, 50)->page);
        self::assertSame(1, Pagination::of(0, 1, 50)->pages);
        self::assertSame(100, Pagination::of(120, 3, 50)->offset());
    }

    public function testWindowShowsBothEndsAroundTheCurrentPage(): void
    {
        self::assertSame([1, null, 35, 36, 37, 38, 39, null, 120], Pagination::of(6000, 37, 50)->window());
        self::assertSame([1, 2, 3, 4, 5, 6], Pagination::of(300, 4, 50)->window());
        self::assertSame([1], Pagination::of(10, 1, 50)->window());
    }
}
