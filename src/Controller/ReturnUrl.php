<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Controller;

final readonly class ReturnUrl
{
    private const int MAX_LENGTH = 2048;

    public function __construct(private string $pathPrefix)
    {
    }

    public static function forBaseUrl(string $baseUrl): self
    {
        return new self(rtrim((string) parse_url($baseUrl, \PHP_URL_PATH), '/'));
    }

    public static function relative(string $url): string
    {
        $query = parse_url($url, \PHP_URL_QUERY);

        return (string) parse_url($url, \PHP_URL_PATH).(\is_string($query) ? '?'.$query : '');
    }

    public function validated(mixed $candidate): ?string
    {
        if (!\is_string($candidate) || !$this->isSafe($candidate)) {
            return null;
        }

        return $candidate;
    }

    private function isSafe(string $candidate): bool
    {
        return '' !== $this->pathPrefix
            && \strlen($candidate) <= self::MAX_LENGTH
            && 1 !== preg_match('#[\\\\\s]|^//|^[a-z][a-z0-9+.-]*:#i', $candidate)
            && 1 === preg_match('#^'.preg_quote($this->pathPrefix, '#').'(?:[/?]|$)#', $candidate)
            && !str_contains((string) parse_url($candidate, \PHP_URL_PATH), '..');
    }
}
