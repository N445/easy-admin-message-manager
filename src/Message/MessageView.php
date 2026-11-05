<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Message;

use EasyAdminMessengerManager\Stamp\EammMessageIdStamp;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\MessageDecodingFailedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

final readonly class MessageView
{
    private const string STREAM_ID_PATTERN = '/^(\d{10,})-\d+$/';

    public function __construct(
        public string $id,
        public string $transport,
        public string $messageClass,
        public bool $decodable,
        public ?string $originalTransport,
        public ?string $exceptionClass,
        public ?string $exceptionMessage,
        public ?FlattenException $flattenException,
        public int $retryCount,
        public ?\DateTimeInterface $failedAt,
        public Envelope $envelope,
        public ?\DateTimeImmutable $queuedAt = null,
    ) {
    }

    public static function fromEnvelope(Envelope $envelope, string $transport, ?\DateTimeImmutable $queuedAt = null): self
    {
        $error = $envelope->last(ErrorDetailsStamp::class);
        $id = (string) $envelope->last(TransportMessageIdStamp::class)?->getId();

        return new self(
            $id,
            $transport,
            self::messageClassOf($envelope->getMessage()),
            self::isDecodable($envelope),
            $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName(),
            $error?->getExceptionClass(),
            $error?->getExceptionMessage(),
            $error?->getFlattenException(),
            $envelope->last(RedeliveryStamp::class)?->getRetryCount() ?? 0,
            $envelope->last(RedeliveryStamp::class)?->getRedeliveredAt(),
            $envelope,
            $queuedAt ?? self::queuedAtFromStreamId($id),
        );
    }

    public function trackingId(): ?string
    {
        return $this->envelope->last(EammMessageIdStamp::class)?->id;
    }

    public function shortClass(): string
    {
        $position = strrpos($this->messageClass, '\\');
        if (false === $position) {
            return $this->messageClass;
        }

        return substr($this->messageClass, $position + 1);
    }

    /**
     * @return array<class-string, list<object>>
     */
    public function stamps(): array
    {
        return $this->envelope->all();
    }

    private static function queuedAtFromStreamId(string $id): ?\DateTimeImmutable
    {
        if (1 !== preg_match(self::STREAM_ID_PATTERN, $id, $match)) {
            return null;
        }

        return \DateTimeImmutable::createFromTimestamp((int) $match[1] / 1000)->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    private static function messageClassOf(object $message): string
    {
        if ($message instanceof \__PHP_Incomplete_Class) {
            return (string) ((array) $message)['__PHP_Incomplete_Class_Name'];
        }

        return $message::class;
    }

    private static function isDecodable(Envelope $envelope): bool
    {
        if ($envelope->getMessage() instanceof \__PHP_Incomplete_Class) {
            return false;
        }

        return null === $envelope->last(MessageDecodingFailedStamp::class);
    }
}
