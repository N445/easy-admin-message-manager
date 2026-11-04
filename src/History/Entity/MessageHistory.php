<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\History\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: MessageHistory::TABLE)]
#[ORM\Index(name: 'eamm_history_handled_at_idx', columns: ['handled_at'])]
#[ORM\Index(name: 'eamm_history_class_idx', columns: ['message_class'])]
#[ORM\Index(name: 'eamm_history_status_idx', columns: ['status', 'handled_at'])]
class MessageHistory
{
    public const string TABLE = 'eamm_message_history';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\Column(name: 'message_class', length: 255)]
    private string $messageClass;

    #[ORM\Column(length: 100)]
    private string $transport;

    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $attempt;

    #[ORM\Column(name: 'duration_ms', nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(name: 'memory_bytes', nullable: true)]
    private ?int $memoryBytes = null;

    #[ORM\Column(name: 'error_class', length: 255, nullable: true)]
    private ?string $errorClass = null;

    #[ORM\Column(name: 'error_message', type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'error_trace', type: Types::TEXT, nullable: true)]
    private ?string $errorTrace = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $payload = null;

    #[ORM\Column(name: 'handled_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $handledAt;
}
