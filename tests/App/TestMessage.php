<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\App;

final readonly class TestMessage
{
    public function __construct(
        public string $label,
        public bool $fails = false,
        #[\SensitiveParameter] public string $apiSecretValue = 'hidden-value',
        public string $password = 'p4ss',
    ) {
    }
}
