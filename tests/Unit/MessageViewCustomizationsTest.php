<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Unit;

use EasyAdminMessengerManager\Customization\MessageViewCustomizations;
use EasyAdminMessengerManager\Message\MessageView;
use EasyAdminMessengerManager\Tests\App\TestMessage;
use EasyAdminMessengerManager\Tests\App\TestMessageViewCustomizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;

final class MessageViewCustomizationsTest extends TestCase
{
    public function testWithoutCustomizerTheShortClassIsKept(): void
    {
        $customizations = new MessageViewCustomizations();

        self::assertSame('TestMessage', $customizations->label($this->view('custom')));
        self::assertSame([], $customizations->columns());
        self::assertSame([], $customizations->actions($this->view('custom')));
    }

    public function testCustomizersProvideColumnsActionsAndLabels(): void
    {
        $customizations = new MessageViewCustomizations([new TestMessageViewCustomizer()]);

        self::assertSame('Custom <label>', $customizations->label($this->view('custom')));
        self::assertSame('TestMessage', $customizations->label($this->view('other')));
        self::assertSame('other', $customizations->columns()[0]->valueOf($this->view('other')));
        self::assertSame(['Open in app', 'Admin only'], array_map(static fn ($action): string => $action->label, $customizations->actions($this->view('custom'))));
        self::assertSame([], $customizations->actions($this->view('other')));
    }

    private function view(string $label): MessageView
    {
        return MessageView::fromEnvelope(new Envelope(new TestMessage($label)), 'async');
    }
}
