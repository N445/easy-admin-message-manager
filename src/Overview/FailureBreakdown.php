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
     */
    public function __construct(
        public array $byClass,
        public array $byException,
        public array $byOrigin,
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
        );
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
