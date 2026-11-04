<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Message;

use EasyAdminMessengerManager\Transport\TransportException;
use EasyAdminMessengerManager\Transport\TransportRegistry;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final readonly class MessageFinder
{
    public function __construct(
        private TransportRegistry $transports,
        private ?PhpSerializer $phpSerializer = null,
    ) {
    }

    /**
     * @return list<MessageView>
     */
    public function scan(string $transport, int $limit): array
    {
        return $this->read($transport, $this->transports->listable($transport), $limit);
    }

    public function find(string $transport, string $id): MessageView
    {
        return MessageView::fromEnvelope($this->findEnvelope($transport, $id), $transport);
    }

    public function findEnvelope(string $transport, string $id): Envelope
    {
        $receiver = $this->transports->listable($transport);
        $this->phpSerializer?->acceptPhpIncompleteClass();
        try {
            $envelope = $receiver->find($id);
        } finally {
            $this->phpSerializer?->rejectPhpIncompleteClass();
        }

        return $envelope ?? throw TransportException::messageNotFound($transport, $id);
    }

    /**
     * @return list<MessageView>
     */
    private function read(string $transport, ListableReceiverInterface $receiver, int $limit): array
    {
        $this->phpSerializer?->acceptPhpIncompleteClass();
        try {
            $envelopes = iterator_to_array($receiver->all($limit), false);
        } finally {
            $this->phpSerializer?->rejectPhpIncompleteClass();
        }

        return array_map(static fn (Envelope $envelope): MessageView => MessageView::fromEnvelope($envelope, $transport), $envelopes);
    }
}
