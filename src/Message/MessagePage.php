<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Message;

use EasyAdminMessengerManager\Controller\Pagination;

final readonly class MessagePage
{
    /**
     * @param list<MessageView> $views
     */
    public function __construct(
        public array $views,
        public Pagination $pagination,
        public bool $complete,
    ) {
    }
}
