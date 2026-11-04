<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Message\Source;

use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\MessageView;

interface MessageSource
{
    public function count(MessageFilter $filter): int;

    /**
     * @return list<MessageView>
     */
    public function slice(MessageFilter $filter, int $offset, int $limit): array;

    /**
     * @return list<string>
     */
    public function ids(MessageFilter $filter): array;

    public function isComplete(): bool;

    public function remove(MessageFilter $filter): int;
}
