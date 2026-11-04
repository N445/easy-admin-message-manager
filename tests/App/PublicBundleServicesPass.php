<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\App;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class PublicBundleServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if (str_starts_with($id, 'EasyAdminMessengerManager\\')) {
                $definition->setPublic(true);
            }
        }
    }
}
