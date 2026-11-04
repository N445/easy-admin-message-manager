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
}
