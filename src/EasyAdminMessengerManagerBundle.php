<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager;

use EasyAdminMessengerManager\Customization\MessageViewCustomizer;
use EasyAdminMessengerManager\DependencyInjection\TransportLocatorPass;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class EasyAdminMessengerManagerBundle extends AbstractBundle
{
    protected string $extensionAlias = 'easy_admin_messenger_manager';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->import('../config/definition.php');
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TransportLocatorPass());
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('easy_admin_messenger_manager.permissions', $config['permissions'])
            ->set('easy_admin_messenger_manager.redaction_keys', $config['redaction']['keys'])
            ->set('easy_admin_messenger_manager.batch_transport', $config['batch']['transport'])
            ->set('easy_admin_messenger_manager.batch_chunk_size', $config['batch']['chunk_size'])
            ->set('easy_admin_messenger_manager.max_scan', $config['max_scan'])
            ->set('easy_admin_messenger_manager.breakdown_sample', $config['breakdown_sample'])
            ->set('easy_admin_messenger_manager.history_enabled', $config['history']['enabled'])
            ->set('easy_admin_messenger_manager.history_retention_days', $config['history']['retention_days'])
            ->set('easy_admin_messenger_manager.history_capture_payload', $config['history']['capture_payload'])
            ->set('easy_admin_messenger_manager.history_exclude', $config['history']['exclude']);

        $container->import('../config/services.php');
        $builder->registerForAutoconfiguration(MessageViewCustomizer::class)->addTag(MessageViewCustomizer::TAG);

        if (!$config['history']['enabled']) {
            return;
        }

        $builder->setAlias('easy_admin_messenger_manager.history_connection', \sprintf('doctrine.dbal.%s_connection', $config['history']['connection']));
        $container->import('../config/history.php');
    }
}
