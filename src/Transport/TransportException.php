<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Transport;

final class TransportException extends \RuntimeException
{
    public static function unknown(string $name): self
    {
        return new self(\sprintf('Unknown messenger transport "%s".', $name));
    }

    public static function notListable(string $name): self
    {
        return new self(\sprintf('The "%s" transport cannot list its messages.', $name));
    }

    public static function notFailure(string $name): self
    {
        return new self(\sprintf('The "%s" transport is not a failure transport.', $name));
    }

    public static function notSender(string $name): self
    {
        return new self(\sprintf('The "%s" transport cannot receive new messages.', $name));
    }

    public static function messageNotFound(string $transport, string $id): self
    {
        return new self(\sprintf('Message "%s" not found in the "%s" transport.', $id, $transport));
    }

    public static function noOriginalTransport(string $id): self
    {
        return new self(\sprintf('Message "%s" has no original transport to be sent back to.', $id));
    }
}
