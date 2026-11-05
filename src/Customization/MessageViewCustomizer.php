<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Customization;

use EasyAdminMessengerManager\Message\MessageView;

interface MessageViewCustomizer
{
    public const string TAG = 'easy_admin_messenger_manager.message_view_customizer';

    /**
     * @return iterable<MessageColumn>
     */
    public function columns(): iterable;

    /**
     * @return iterable<MessageAction>
     */
    public function actions(MessageView $view): iterable;

    public function label(MessageView $view): ?string;
}
