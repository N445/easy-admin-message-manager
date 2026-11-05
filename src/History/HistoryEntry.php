<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\History;

final readonly class HistoryEntry
{
    public function __construct(
        public string $messageClass,
        public string $transport,
        public HistoryStatus $status,
        public int $attempt,
        public ?int $durationMs,
        public ?int $memoryBytes,
        public ?\Throwable $error,
        public ?string $payload,
        public ?string $messageId = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(\DateTimeImmutable $handledAt): array
    {
        return [
            'message_class' => substr($this->messageClass, 0, 255),
            'transport' => substr($this->transport, 0, 100),
            'status' => $this->status->value,
            'attempt' => $this->attempt,
            'duration_ms' => $this->durationMs,
            'memory_bytes' => $this->memoryBytes,
            'error_class' => null === $this->error ? null : substr($this->rootError()::class, 0, 255),
            'error_message' => $this->rootError()?->getMessage(),
            'error_trace' => $this->trace(),
            'payload' => $this->payload,
            'handled_at' => $handledAt->format('Y-m-d H:i:s'),
            'message_id' => $this->messageId,
        ];
    }

    private function trace(): ?string
    {
        $trace = $this->rootError()?->getTraceAsString();

        return null === $trace ? null : mb_substr($trace, 0, 20000);
    }

    private function rootError(): ?\Throwable
    {
        $error = $this->error;
        while (null !== $error?->getPrevious()) {
            $error = $error->getPrevious();
        }

        return $error;
    }
}
