<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Unit;

use EasyAdminMessengerManager\Overview\ErrorMessagePattern;
use EasyAdminMessengerManager\Overview\FailureBreakdown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorMessagePatternTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function messages(): iterable
    {
        yield 'digits' => ['Album 1234 not found', 'Album # not found', 'Album'];
        yield 'uuid' => ['Media 0189f7a2-5b1c-7d3e-9f00-1a2b3c4d5e6f is gone', 'Media ? is gone', 'Media'];
        yield 'hex id' => ['Object 0x7f3a9c00 freed, hash 9f86d081884c7d659a2feaa0', 'Object ? freed, hash ?', 'Object'];
        yield 'long quoted string' => ['File "/var/www/html/public/upload/very/long/path.jpg" missing', 'File "/var/www/html/pub…" missing', 'File "/var/www/html/pub'];
        yield 'short quoted string' => ['Unknown format "png"', 'Unknown format "png"', 'Unknown format "png"'];
        yield 'leading number' => ['404 returned by https://api.example', '# returned by https://api.example', 'returned by https://api.example'];
        yield 'empty' => ['', '', ''];
    }

    #[DataProvider('messages')]
    public function testNormalisesVariablePartsAndKeepsASearchablePrefix(string $message, string $pattern, string $searchTerm): void
    {
        $normalised = ErrorMessagePattern::of($message);

        self::assertSame($pattern, (string) $normalised);
        self::assertSame($searchTerm, $normalised->searchTerm());
        self::assertSame($searchTerm, FailureBreakdown::searchTerm($pattern));
    }
}
