<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\DependencyInjection;

use EasyAdminMessengerManager\Message\Source\MessageSources;
use EasyAdminMessengerManager\Transport\TransportRegistry;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class TransportLocatorPass implements CompilerPassInterface
{
    private const string SYNC_DSN_PREFIX = 'sync://';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(TransportRegistry::class)) {
            return;
        }

        $references = [];
        $failureNames = [];
        foreach ($container->findTaggedServiceIds('messenger.receiver') as $id => $tags) {
            foreach ($tags as $tag) {
                $this->collect($id, $tag, $references, $failureNames);
            }
        }

        $this->assertBatchTransportExists($container, array_keys($references));
        $references = array_filter($references, fn (Reference $reference): bool => !$this->isSync($container, $reference));

        $container->getDefinition(TransportRegistry::class)
            ->setArgument('$transports', ServiceLocatorTagPass::register($container, $references))
            ->setArgument('$transportNames', array_keys($references))
            ->setArgument('$failureTransportNames', array_values(array_intersect($failureNames, array_keys($references))));

        $this->configureSources($container, $references);
    }

    /**
     * @param array<string, mixed>     $tag
     * @param array<string, Reference> $references
     * @param list<string>             $failureNames
     */
    private function collect(string $id, array $tag, array &$references, array &$failureNames): void
    {
        $alias = $tag['alias'] ?? null;
        if (null === $alias) {
            return;
        }

        $references[$alias] = new Reference($id);
        if ($tag['is_failure_transport'] ?? false) {
            $failureNames[] = $alias;
        }
    }

    /**
     * @param list<string> $transportNames
     */
    private function assertBatchTransportExists(ContainerBuilder $container, array $transportNames): void
    {
        $batchTransport = $container->getParameter('easy_admin_messenger_manager.batch_transport');
        if (null === $batchTransport || \in_array($batchTransport, $transportNames, true)) {
            return;
        }

        throw new InvalidConfigurationException(\sprintf('The transport "%s" configured in "easy_admin_messenger_manager.batch.transport" does not exist. Available transports: "%s".', $batchTransport, implode('", "', $transportNames)));
    }

    private function isSync(ContainerBuilder $container, Reference $reference): bool
    {
        $dsn = $container->findDefinition((string) $reference)->getArguments()[0] ?? null;

        return \is_string($dsn) && str_starts_with($dsn, self::SYNC_DSN_PREFIX);
    }

    /**
     * @param array<string, Reference> $transports
     */
    private function configureSources(ContainerBuilder $container, array $transports): void
    {
        $configs = [];
        $serializers = [];
        foreach ($transports as $name => $reference) {
            $arguments = $container->findDefinition((string) $reference)->getArguments();
            if (!isset($arguments[0], $arguments[2]) || !\is_string($arguments[0]) || !$arguments[2] instanceof Reference) {
                continue;
            }

            $configs[$name] = ['dsn' => $arguments[0], 'options' => $arguments[1] ?? []];
            $serializers[$name] = $arguments[2];
        }

        $container->getDefinition(MessageSources::class)
            ->setArgument('$transportConfigs', $configs)
            ->setArgument('$serializers', ServiceLocatorTagPass::register($container, $serializers));
    }
}
