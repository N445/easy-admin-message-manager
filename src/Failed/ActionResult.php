<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Failed;

final readonly class ActionResult
{
    private function __construct(
        public bool $succeeded,
        public ?string $error,
    ) {
    }

    public static function success(): self
    {
        return new self(true, null);
    }

    public static function failure(\Throwable $throwable): self
    {
        return new self(false, $throwable->getMessage());
    }
}
