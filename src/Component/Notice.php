<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Component;

final readonly class Notice
{
    /**
     * @param array<string, int|string> $parameters
     */
    private function __construct(
        public string $type,
        public string $message,
        public array $parameters = [],
    ) {
    }

    /**
     * @param array<string, int|string> $parameters
     */
    public static function success(string $message, array $parameters = []): self
    {
        return new self('success', $message, $parameters);
    }

    public static function error(string $message): self
    {
        return new self('danger', $message);
    }
}
