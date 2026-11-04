<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\App;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TestMessageHandler
{
    public function __invoke(TestMessage $message): void
    {
        if ($message->fails) {
            throw new \DomainException('Handler failed for '.$message->label);
        }
    }
}
