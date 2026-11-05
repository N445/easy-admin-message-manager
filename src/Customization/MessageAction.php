<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Customization;

final readonly class MessageAction
{
    public function __construct(
        public string $label,
        public string $url,
        public ?string $icon = null,
        public ?string $permission = null,
    ) {
    }
}
