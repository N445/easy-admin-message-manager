<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Customization;

use EasyAdminMessengerManager\Message\MessageView;

final class MessageViewCustomizations
{
    /** @var list<MessageColumn>|null */
    private ?array $columns = null;

    /**
     * @param iterable<MessageViewCustomizer> $customizers
     */
    public function __construct(private readonly iterable $customizers = [])
    {
    }

    /**
     * @return list<MessageColumn>
     */
    public function columns(): array
    {
        return $this->columns ??= $this->collect(static fn (MessageViewCustomizer $customizer): iterable => $customizer->columns());
    }

    /**
     * @return list<MessageAction>
     */
    public function actions(MessageView $view): array
    {
        return $this->collect(static fn (MessageViewCustomizer $customizer): iterable => $customizer->actions($view));
    }

    public function label(MessageView $view): string
    {
        foreach ($this->customizers as $customizer) {
            $label = $customizer->label($view);
            if (null !== $label) {
                return $label;
            }
        }

        return $view->shortClass();
    }

    /**
     * @template T
     *
     * @param \Closure(MessageViewCustomizer): iterable<T> $extract
     *
     * @return list<T>
     */
    private function collect(\Closure $extract): array
    {
        $items = [];
        foreach ($this->customizers as $customizer) {
            array_push($items, ...iterator_to_array($extract($customizer), false));
        }

        return $items;
    }
}
