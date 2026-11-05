<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Uid\Uuid;

final class EammMessageIdStamp implements StampInterface
{
    public function __construct(public readonly string $id)
    {
    }

    public static function generate(): self
    {
        return new self(Uuid::v7()->toRfc4122());
    }
}
