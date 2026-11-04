<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Overview;

final readonly class TransportSummary
{
    public function __construct(
        public string $name,
        public ?int $count,
        public bool $listable,
        public bool $failure,
        public ?string $error = null,
    ) {
    }
}
