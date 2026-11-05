<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Batch;

use EasyAdminMessengerManager\Message\MessageView;

final class MessageSelection
{
    private const string SEPARATOR = '::';

    /** @var array<string, list<string>> */
    private array $idsByTransport = [];

    /**
     * @param list<MessageView> $views
     */
    public static function fromViews(array $views): self
    {
        $selection = new self();
        foreach ($views as $view) {
            $selection->add($view->transport, $view->id);
        }

        return $selection;
    }

    /**
     * @param list<string> $references
     */
    public static function fromReferences(array $references): self
    {
        $selection = new self();
        foreach ($references as $reference) {
            $selection->addReference((string) $reference);
        }

        return $selection;
    }

    /**
     * @param list<string> $ids
     */
    public function with(string $transport, array $ids): self
    {
        $selection = clone $this;
        foreach ($ids as $id) {
            $selection->add($transport, $id);
        }

        return $selection;
    }

    public static function reference(MessageView $view): string
    {
        return self::referenceOf($view->transport, $view->id);
    }

    public static function referenceOf(string $transport, string $id): string
    {
        return $transport.self::SEPARATOR.$id;
    }

    /**
     * @return array<string, list<string>>
     */
    public function idsByTransport(): array
    {
        return $this->idsByTransport;
    }

    public function count(): int
    {
        return array_sum(array_map(\count(...), $this->idsByTransport));
    }

    private function addReference(string $reference): void
    {
        $parts = explode(self::SEPARATOR, $reference, 2);
        if (2 !== \count($parts) || '' === $parts[0] || '' === $parts[1]) {
            return;
        }

        $this->add($parts[0], $parts[1]);
    }

    private function add(string $transport, string $id): void
    {
        $this->idsByTransport[$transport][] = $id;
    }
}
