<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\App;

use EasyAdminMessengerManager\Customization\MessageAction;
use EasyAdminMessengerManager\Customization\MessageColumn;
use EasyAdminMessengerManager\Customization\MessageViewCustomizer;
use EasyAdminMessengerManager\Message\MessageView;

final class TestMessageViewCustomizer implements MessageViewCustomizer
{
    public function columns(): iterable
    {
        yield new MessageColumn('Label', self::labelOf(...));
    }

    public function actions(MessageView $view): iterable
    {
        if ('custom' !== self::labelOf($view)) {
            return [];
        }

        return [
            new MessageAction('Open in app', '/app/custom', 'fa fa-link'),
            new MessageAction('Admin only', '/app/admin', permission: 'ROLE_ADMIN'),
        ];
    }

    public function label(MessageView $view): ?string
    {
        return 'custom' === self::labelOf($view) ? 'Custom <label>' : null;
    }

    private static function labelOf(MessageView $view): string
    {
        $message = $view->envelope->getMessage();

        return $message instanceof TestMessage ? $message->label : '';
    }
}
