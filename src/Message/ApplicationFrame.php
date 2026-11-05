<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Message;

use Symfony\Component\ErrorHandler\Exception\FlattenException;

final readonly class ApplicationFrame
{
    private function __construct(
        public string $location,
        public string $call,
    ) {
    }

    public static function of(?FlattenException $exception, string $projectDir): ?self
    {
        $frames = array_filter($exception?->getTrace() ?? [], self::isApplicationFrame(...));
        $frame = reset($frames);
        if (false === $frame) {
            return null;
        }

        return new self(
            \sprintf('%s:%d', self::relativePath((string) $frame['file'], $projectDir), (int) ($frame['line'] ?? 0)),
            ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $frame
     */
    private static function isApplicationFrame(array $frame): bool
    {
        $file = (string) ($frame['file'] ?? '');

        return '' !== $file && !str_contains(strtr($file, '\\', '/'), '/vendor/');
    }

    private static function relativePath(string $file, string $projectDir): string
    {
        $prefix = rtrim($projectDir, '/').'/';

        return str_starts_with($file, $prefix) ? substr($file, \strlen($prefix)) : $file;
    }
}
