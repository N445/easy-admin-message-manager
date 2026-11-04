<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\Source\MessageSources;
use EasyAdminMessengerManager\Tests\App\TestKernel;
use EasyAdminMessengerManager\Tests\App\TestMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;

abstract class MessengerTestCase extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    protected function addFailed(TestMessage $message, string $origin = 'async', string $error = 'Boom'): void
    {
        $envelope = (new Envelope($message))->with(
            new SentToFailureTransportStamp($origin),
            ErrorDetailsStamp::create(new \DomainException($error)),
            new RedeliveryStamp(2),
        );
        $this->transport('failed')->send($envelope);
    }

    /**
     * @return list<\EasyAdminMessengerManager\Message\MessageView>
     */
    protected function failedViews(?MessageFilter $filter = null): array
    {
        return self::getContainer()->get(MessageSources::class)->failed()->page($filter ?? new MessageFilter(), 1, 1000)->views;
    }

    protected function transport(string $name): TransportInterface
    {
        return self::getContainer()->get('messenger.transport.'.$name);
    }

    protected function async(): InMemoryTransport
    {
        $transport = $this->transport('async');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }
}
