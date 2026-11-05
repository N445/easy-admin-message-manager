<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Unit;

use EasyAdminMessengerManager\EasyAdminMessengerManagerBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Config\FileLocator;

final class MigrationsPathTest extends TestCase
{
    public function testRegistersTheBundleMigrationsWhenHistoryIsEnabled(): void
    {
        $builder = $this->builder(['history' => ['enabled' => true]]);

        $paths = $builder->getExtensionConfig('doctrine_migrations')[0]['migrations_paths'];

        self::assertSame(\dirname(__DIR__, 2).'/migrations', $paths['EasyAdminMessengerManager\Migrations']);
        self::assertFileExists($paths['EasyAdminMessengerManager\Migrations'].'/Version20261104000000.php');
    }

    public function testDoesNothingWhenHistoryIsDisabled(): void
    {
        self::assertSame([], $this->builder([])->getExtensionConfig('doctrine_migrations'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function builder(array $config): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'doctrine_migrations';
            }
        });
        $builder->prependExtensionConfig('easy_admin_messenger_manager', $config);
        $bundle = new EasyAdminMessengerManagerBundle();
        $loader = new PhpFileLoader($builder, new FileLocator(__DIR__));
        $instanceof = [];
        $bundle->prependExtension(new ContainerConfigurator($builder, $loader, $instanceof, __DIR__, __FILE__), $builder);

        return $builder;
    }
}
