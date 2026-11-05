<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Overview;

final readonly class ErrorMessagePattern
{
    private const string NUMBER = '#';
    private const string IDENTIFIER = '?';
    private const string ELLIPSIS = '…';
    private const int QUOTE_LENGTH = 20;

    /** @var array<string, string> */
    private const array IDENTIFIERS = [
        '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => self::IDENTIFIER,
        '/\b0x[0-9a-f]+\b/i' => self::IDENTIFIER,
        '/\b(?=[0-9a-f]*\d)(?=[0-9a-f]*[a-f])[0-9a-f]{12,}\b/i' => self::IDENTIFIER,
        '/\d+/' => self::NUMBER,
    ];

    private function __construct(private string $pattern)
    {
    }

    public static function of(string $message): self
    {
        $shortened = (string) preg_replace_callback('/(["\'])([^"\']{'.self::QUOTE_LENGTH.',})\1/u', self::shortenQuote(...), trim($message));

        return new self((string) preg_replace(array_keys(self::IDENTIFIERS), array_values(self::IDENTIFIERS), $shortened));
    }

    public function __toString(): string
    {
        return $this->pattern;
    }

    public function searchTerm(): string
    {
        $literals = array_map(trim(...), preg_split('/['.preg_quote(self::NUMBER.self::IDENTIFIER, '/').self::ELLIPSIS.']/u', $this->pattern) ?: []);
        if ('' !== $literals[0]) {
            return $literals[0];
        }

        usort($literals, static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        return $literals[0];
    }

    /**
     * @param array<int, string> $match
     */
    private static function shortenQuote(array $match): string
    {
        return $match[1].mb_substr($match[2], 0, self::QUOTE_LENGTH - 3).self::ELLIPSIS.$match[1];
    }
}
