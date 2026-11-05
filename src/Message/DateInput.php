<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Message;

final readonly class DateInput
{
    private const string DAY_FORMAT = 'Y-m-d';
    public const string MINUTE_FORMAT = 'Y-m-d\\TH:i';

    private function __construct(
        private string $dayTime,
        private string $seconds,
        private string $wholeDayTime,
    ) {
    }

    public static function start(): self
    {
        return new self('00:00:00', ':00', '00:00');
    }

    public static function end(): self
    {
        return new self('23:59:59', ':59', '23:59');
    }

    public function parse(mixed $value): ?\DateTimeImmutable
    {
        $text = trim((string) $value);
        if ('' === $text) {
            return null;
        }

        return self::parseFormat('!'.self::DAY_FORMAT.' H:i:s', $text.' '.$this->dayTime)
            ?? self::parseFormat('!'.self::MINUTE_FORMAT.':s', $text.$this->seconds);
    }

    public function format(?\DateTimeImmutable $date): ?string
    {
        if (null === $date) {
            return null;
        }

        if ($date->format('H:i') === $this->wholeDayTime) {
            return $date->format(self::DAY_FORMAT);
        }

        return $date->format(self::MINUTE_FORMAT);
    }

    public static function input(?\DateTimeImmutable $date): string
    {
        return (string) $date?->format(self::MINUTE_FORMAT);
    }

    private static function parseFormat(string $format, string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat($format, $value);
        if (false === $date || [] !== array_filter((array) \DateTimeImmutable::getLastErrors())) {
            return null;
        }

        return $date;
    }
}
