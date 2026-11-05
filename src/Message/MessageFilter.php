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
        public ?string $trackingId = null,
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
            DateInput::start()->parse($query['from'] ?? null),
            DateInput::end()->parse($query['to'] ?? null),
            self::text($query, 'tracking'),
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
            'from' => DateInput::start()->format($this->from),
            'to' => DateInput::end()->format($this->to),
            'tracking' => $this->trackingId,
        ], static fn (?string $value): bool => null !== $value);
    }

    public function fromInput(): string
    {
        return DateInput::input($this->from);
    }

    public function toInput(): string
    {
        return DateInput::input($this->to);
    }

    public function isEmpty(): bool
    {
        return [] === $this->toArray();
    }

    public function matches(MessageView $view, bool $byQueuedAt = false): bool
    {
        return $this->matchesClass($view)
            && $this->matchesOrigin($view)
            && $this->matchesException($view)
            && $this->matchesTrackingId($view)
            && $this->matchesPeriod($byQueuedAt ? $view->queuedAt : $view->failedAt);
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

    private function matchesTrackingId(MessageView $view): bool
    {
        return null === $this->trackingId || $view->trackingId() === $this->trackingId;
    }

    private function matchesPeriod(?\DateTimeInterface $date): bool
    {
        if (null === $this->from && null === $this->to) {
            return true;
        }

        if (null === $date) {
            return false;
        }

        return $date >= ($this->from ?? $date) && $date <= ($this->to ?? $date);
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function text(array $query, string $key): ?string
    {
        $value = trim((string) ($query[$key] ?? ''));

        return '' === $value ? null : $value;
    }
}
