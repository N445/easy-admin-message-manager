<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Unit;

use EasyAdminMessengerManager\Controller\ReturnUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReturnUrlTest extends TestCase
{
    public function testAcceptsPathsOfTheDashboardController(): void
    {
        $returnUrl = ReturnUrl::forBaseUrl('https://example.com/admin/queues/');

        self::assertSame('/admin/queues/failed?exception=timeout&page=3', $returnUrl->validated('/admin/queues/failed?exception=timeout&page=3'));
        self::assertSame('/admin/queues', $returnUrl->validated('/admin/queues'));
        self::assertSame('/admin/queues?x=1', $returnUrl->validated('/admin/queues?x=1'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function maliciousValues(): iterable
    {
        yield 'absolute url' => ['https://evil.example/admin/queues/failed'];
        yield 'protocol relative' => ['//evil.example/admin/queues/failed'];
        yield 'backslashes' => ['/\\evil.example/admin/queues'];
        yield 'javascript' => ['javascript:alert(1)//admin/queues'];
        yield 'other controller' => ['/admin/users'];
        yield 'same prefix, other path' => ['/admin/queuesevil'];
        yield 'traversal' => ['/admin/queues/../users'];
        yield 'whitespace' => ["/admin/queues/\nfailed"];
        yield 'array' => [['/admin/queues']];
        yield 'empty' => [''];
        yield 'too long' => ['/admin/queues/'.str_repeat('a', 3000)];
    }

    #[DataProvider('maliciousValues')]
    public function testRejectsEverythingElse(mixed $candidate): void
    {
        self::assertNull(ReturnUrl::forBaseUrl('https://example.com/admin/queues/')->validated($candidate));
    }

    public function testRelativeKeepsPathAndQuery(): void
    {
        self::assertSame('/admin/queues/failed?class=App', ReturnUrl::relative('http://localhost/admin/queues/failed?class=App'));
        self::assertSame('/admin/queues/failed', ReturnUrl::relative('http://localhost/admin/queues/failed'));
    }
}
