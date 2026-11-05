<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Overview;

use EasyAdminMessengerManager\Message\MessageView;

final readonly class FailureBreakdown
{
    /**
     * @param array<string, int> $byClass
     * @param array<string, int> $byException
     * @param array<string, int> $byOrigin
     * @param array<string, int> $byMessage
     */
    public function __construct(
        public array $byClass,
        public array $byException,
        public array $byOrigin,
        public array $byMessage = [],
    ) {
    }

    /**
     * @param list<MessageView> $views
     */
    public static function of(array $views): self
    {
        return new self(
            self::countBy($views, static fn (MessageView $view): string => $view->messageClass),
            self::countBy($views, static fn (MessageView $view): string => $view->exceptionClass ?? '?'),
            self::countBy($views, static fn (MessageView $view): string => $view->originalTransport ?? '?'),
            self::countBy($views, static fn (MessageView $view): string => (string) ErrorMessagePattern::of($view->exceptionMessage ?? '')),
        );
    }

    public static function searchTerm(string $messagePattern): string
    {
        return ErrorMessagePattern::of($messagePattern)->searchTerm();
    }

    public function isEmpty(): bool
    {
        return [] === $this->byClass;
    }

    /**
     * @param list<MessageView>             $views
     * @param \Closure(MessageView): string $key
     *
     * @return array<string, int>
     */
    private static function countBy(array $views, \Closure $key): array
    {
        $counts = array_count_values(array_map($key, $views));
        arsort($counts);

        return $counts;
    }
}
