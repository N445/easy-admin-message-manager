<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Message;

final readonly class MessageFilter
{
    public function __construct(
        public ?string $messageClass = null,
        public ?string $originalTransport = null,
        public ?string $exception = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromArray(array $query): self
    {
        return new self(
            self::text($query, 'class'),
            self::text($query, 'origin'),
            self::text($query, 'exception'),
            self::date($query, 'from', '00:00:00'),
            self::date($query, 'to', '23:59:59'),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'class' => $this->messageClass,
            'origin' => $this->originalTransport,
            'exception' => $this->exception,
            'from' => $this->from?->format('Y-m-d'),
            'to' => $this->to?->format('Y-m-d'),
        ], static fn (?string $value): bool => null !== $value);
    }

    public function isEmpty(): bool
    {
        return [] === $this->toArray();
    }

    public function matches(MessageView $view): bool
    {
        return $this->matchesClass($view)
            && $this->matchesOrigin($view)
            && $this->matchesException($view)
            && $this->matchesPeriod($view);
    }

    private function matchesClass(MessageView $view): bool
    {
        return null === $this->messageClass || str_contains(strtolower($view->messageClass), strtolower($this->messageClass));
    }

    private function matchesOrigin(MessageView $view): bool
    {
        return null === $this->originalTransport || $view->originalTransport === $this->originalTransport;
    }

    private function matchesException(MessageView $view): bool
    {
        if (null === $this->exception) {
            return true;
        }

        $haystack = strtolower($view->exceptionClass.' '.$view->exceptionMessage);

        return str_contains($haystack, strtolower($this->exception));
    }

    private function matchesPeriod(MessageView $view): bool
    {
        if (null === $this->from && null === $this->to) {
            return true;
        }

        if (null === $view->failedAt) {
            return false;
        }

        return $view->failedAt >= ($this->from ?? $view->failedAt) && $view->failedAt <= ($this->to ?? $view->failedAt);
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function text(array $query, string $key): ?string
    {
        $value = trim((string) ($query[$key] ?? ''));

        return '' === $value ? null : $value;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function date(array $query, string $key, string $time): ?\DateTimeImmutable
    {
        $value = self::text($query, $key);
        if (null === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value.' '.$time);

        return false === $date ? null : $date;
    }
}
