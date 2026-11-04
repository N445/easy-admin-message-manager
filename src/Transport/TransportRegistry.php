<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Transport;

use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

final readonly class TransportRegistry
{
    /**
     * @param list<string> $transportNames
     * @param list<string> $failureTransportNames
     */
    public function __construct(
        private ContainerInterface $transports,
        private array $transportNames = [],
        private array $failureTransportNames = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->transportNames;
    }

    /**
     * @return list<string>
     */
    public function failureNames(): array
    {
        return $this->failureTransportNames;
    }

    public function isFailure(string $name): bool
    {
        return \in_array($name, $this->failureTransportNames, true);
    }

    public function receiver(string $name): ReceiverInterface
    {
        if (!\in_array($name, $this->transportNames, true)) {
            throw TransportException::unknown($name);
        }

        return $this->transports->get($name);
    }

    public function listable(string $name): ListableReceiverInterface
    {
        $receiver = $this->receiver($name);
        if (!$receiver instanceof ListableReceiverInterface) {
            throw TransportException::notListable($name);
        }

        return $receiver;
    }

    public function failureReceiver(string $name): ListableReceiverInterface
    {
        if (!$this->isFailure($name)) {
            throw TransportException::notFailure($name);
        }

        return $this->listable($name);
    }

    public function sender(string $name): SenderInterface
    {
        $receiver = $this->receiver($name);
        if (!$receiver instanceof SenderInterface) {
            throw TransportException::notSender($name);
        }

        return $receiver;
    }

    public function isListable(string $name): bool
    {
        return $this->receiver($name) instanceof ListableReceiverInterface;
    }

    public function count(string $name): ?int
    {
        $receiver = $this->receiver($name);
        if (!$receiver instanceof MessageCountAwareInterface) {
            return null;
        }

        return $receiver->getMessageCount();
    }
}
