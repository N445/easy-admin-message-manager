<?php

declare(strict_types=1);

use EasyAdminMessengerManager\Batch\BatchDispatcher;
use EasyAdminMessengerManager\Batch\FailedMessagesBatchHandler;
use EasyAdminMessengerManager\Command\MakeMessengerDashboardCommand;
use EasyAdminMessengerManager\Command\PurgeFailedMessagesCommand;
use EasyAdminMessengerManager\Failed\FailedMessageRemover;
use EasyAdminMessengerManager\Failed\FailedMessageRequeuer;
use EasyAdminMessengerManager\Failed\FailedMessageRetrier;
use EasyAdminMessengerManager\Message\MessageFinder;
use EasyAdminMessengerManager\Message\Source\MessageSources;
use EasyAdminMessengerManager\Overview\QueueOverview;
use EasyAdminMessengerManager\Payload\PayloadNormalizer;
use EasyAdminMessengerManager\Security\MessengerManagerVoter;
use EasyAdminMessengerManager\Transport\TransportRegistry;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->private()
            ->autowire()
            ->autoconfigure();

    $services->set(TransportRegistry::class);

    $services->load('EasyAdminMessengerManager\\Component\\', '../src/Component/*Component.php')
        ->exclude('../src/Component/HistoryComponent.php');

    $services->set(MessageSources::class)
        ->arg('$maxScan', param('easy_admin_messenger_manager.max_scan'))
        ->arg('$sampleSize', param('easy_admin_messenger_manager.breakdown_sample'))
        ->arg('$doctrine', service('doctrine')->nullOnInvalid());

    $services->set(MessageFinder::class)
        ->arg('$phpSerializer', service('messenger.transport.native_php_serializer')->nullOnInvalid());

    $services->set(QueueOverview::class);

    $services->set(PayloadNormalizer::class)
        ->arg('$redactedKeys', param('easy_admin_messenger_manager.redaction_keys'));

    $services->set(FailedMessageRetrier::class)
        ->arg('$bus', service('messenger.routable_message_bus'))
        ->arg('$dispatcher', service('event_dispatcher'))
        ->arg('$logger', service('logger')->nullOnInvalid());

    $services->set(FailedMessageRemover::class);
    $services->set(FailedMessageRequeuer::class);

    $services->set(BatchDispatcher::class)
        ->arg('$bus', service('messenger.default_bus'))
        ->arg('$chunkSize', param('easy_admin_messenger_manager.batch_chunk_size'));

    $services->set(FailedMessagesBatchHandler::class)
        ->arg('$logger', service('logger')->nullOnInvalid());

    $services->set(MessengerManagerVoter::class)
        ->arg('$roles', param('easy_admin_messenger_manager.permissions'));

    $services->set(PurgeFailedMessagesCommand::class);

    $services->set(MakeMessengerDashboardCommand::class)
        ->arg('$projectDir', param('kernel.project_dir'))
        ->arg('$filesystem', service('filesystem'));
};
