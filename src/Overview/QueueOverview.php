<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Overview;

use EasyAdminMessengerManager\Transport\TransportRegistry;

final readonly class QueueOverview
{
    public function __construct(private TransportRegistry $transports)
    {
    }

    /**
     * @param list<string> $hiddenTransports
     *
     * @return list<TransportSummary>
     */
    public function summaries(array $hiddenTransports = []): array
    {
        $names = array_diff($this->transports->names(), $hiddenTransports);

        return array_values(array_map($this->summarize(...), $names));
    }

    private function summarize(string $name): TransportSummary
    {
        try {
            return new TransportSummary($name, $this->transports->count($name), $this->transports->isListable($name), $this->transports->isFailure($name));
        } catch (\Throwable $exception) {
            return new TransportSummary($name, null, false, $this->transports->isFailure($name), $exception->getMessage());
        }
    }
}
