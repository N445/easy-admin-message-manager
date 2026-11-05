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
        public ?string $linkUrl = null,
        public ?string $linkLabel = null,
    ) {
    }

    /**
     * @param array<string, int|string> $parameters
     */
    public static function success(string $message, array $parameters = []): self
    {
        return new self('success', $message, $parameters);
    }

    /**
     * @param array<string, int|string> $parameters
     */
    public static function warning(string $message, array $parameters = []): self
    {
        return new self('warning', $message, $parameters);
    }

    public static function error(string $message): self
    {
        return new self('danger', $message);
    }

    public function withLink(string $url, string $label): self
    {
        return new self($this->type, $this->message, $this->parameters, $url, $label);
    }
}
