<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\DependencyInjection;

use EasyAdminMessengerManager\Message\Source\MessageSources;
use EasyAdminMessengerManager\Transport\TransportRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class TransportLocatorPass implements CompilerPassInterface
{
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

        $container->getDefinition(TransportRegistry::class)
            ->setArgument('$transports', ServiceLocatorTagPass::register($container, $references))
            ->setArgument('$transportNames', array_keys($references))
            ->setArgument('$failureTransportNames', $failureNames);

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
