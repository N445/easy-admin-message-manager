<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Customization;

use EasyAdminMessengerManager\Message\MessageView;

final readonly class MessageColumn
{
    /**
     * @param \Closure(MessageView): string $value
     */
    public function __construct(
        public string $label,
        private \Closure $value,
    ) {
    }

    public function valueOf(MessageView $view): string
    {
        return ($this->value)($view);
    }
}
