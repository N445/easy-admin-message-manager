<?php

declare(strict_types=1);

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use EasyAdminMessengerManager\Component\HistoryComponent;
use EasyAdminMessengerManager\History\HistoryRecorder;
use EasyAdminMessengerManager\History\HistoryRepository;
use EasyAdminMessengerManager\History\PurgeHistoryCommand;
use EasyAdminMessengerManager\History\Schema\HistorySchemaListener;
use EasyAdminMessengerManager\History\Schema\SetupHistoryCommand;
use EasyAdminMessengerManager\Stamp\AddMessageIdStampListener;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->private()
            ->autowire()
            ->autoconfigure();

    $services->set(HistoryRepository::class)
        ->arg('$connection', service('easy_admin_messenger_manager.history_connection'));

    $services->set(HistoryRecorder::class)
        ->arg('$excludedClasses', param('easy_admin_messenger_manager.history_exclude'))
        ->arg('$capturePayload', param('easy_admin_messenger_manager.history_capture_payload'))
        ->arg('$logger', service('logger')->nullOnInvalid());

    $services->set(HistoryComponent::class);

    $services->set(AddMessageIdStampListener::class);

    $services->set(PurgeHistoryCommand::class)
        ->arg('$retentionDays', param('easy_admin_messenger_manager.history_retention_days'));

    $services->set(SetupHistoryCommand::class)
        ->arg('$connection', service('easy_admin_messenger_manager.history_connection'));

    if (!class_exists(GenerateSchemaEventArgs::class)) {
        return;
    }

    $services->set(HistorySchemaListener::class)
        ->arg('$historyConnection', service('easy_admin_messenger_manager.history_connection'))
        ->tag('doctrine.event_listener', ['event' => 'postGenerateSchema']);
};
