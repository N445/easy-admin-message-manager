<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Message\Source;

use Doctrine\Persistence\ManagerRegistry;
use EasyAdminMessengerManager\Message\MessageFinder;
use EasyAdminMessengerManager\Transport\TransportRegistry;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\PostgreSqlConnection;

final class MessageSources
{
    /** @var array<string, MessageSource> */
    private array $sources = [];

    /**
     * @param array<string, array{dsn: string, options: array<string, mixed>}> $transportConfigs
     */
    public function __construct(
        private readonly TransportRegistry $transports,
        private readonly MessageFinder $finder,
        private readonly ContainerInterface $serializers,
        private readonly int $maxScan,
        private readonly int $sampleSize,
        private readonly array $transportConfigs = [],
        private readonly ?ManagerRegistry $doctrine = null,
    ) {
    }

    public function failed(): MessagePages
    {
        return $this->pages($this->transports->failureNames());
    }

    public function failedIn(string $name): MessagePages
    {
        $this->transports->failureReceiver($name);

        return $this->pages([$name]);
    }

    public function transport(string $name): MessagePages
    {
        $this->transports->listable($name);

        return $this->pages([$name]);
    }

    /**
     * @param list<string> $names
     */
    private function pages(array $names): MessagePages
    {
        $sources = [];
        foreach ($names as $name) {
            $sources[$name] = $this->source($name);
        }

        return new MessagePages($sources, $this->sampleSize);
    }

    private function source(string $name): MessageSource
    {
        return $this->sources[$name] ??= $this->doctrineSource($name) ?? new ScannedSource($this->finder, $this->transports, $name, $this->maxScan);
    }

    private function doctrineSource(string $name): ?DoctrineSource
    {
        $config = $this->transportConfigs[$name] ?? null;
        if (null === $config || null === $this->doctrine || !str_starts_with($config['dsn'], 'doctrine://') || !class_exists(PostgreSqlConnection::class)) {
            return null;
        }

        $options = array_diff_key($config['options'], ['transport_name' => true, 'use_notify' => true]);
        $configuration = PostgreSqlConnection::buildConfiguration($config['dsn'], $options);

        return new DoctrineSource(
            $this->doctrine->getConnection($configuration['connection']),
            $configuration['table_name'],
            $configuration['queue_name'],
            $this->serializers->get($name),
            $name,
        );
    }
}
