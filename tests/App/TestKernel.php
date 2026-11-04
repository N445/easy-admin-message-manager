<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use EasyAdminMessengerManager\EasyAdminMessengerManagerBundle;
use EasyCorp\Bundle\EasyAdminBundle\EasyAdminBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\LiveComponent\LiveComponentBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new DoctrineBundle();
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new StimulusBundle();
        yield new TwigComponentBundle();
        yield new LiveComponentBundle();
        yield new EasyAdminBundle();
        yield new EasyAdminMessengerManagerBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/eamm-tests/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/eamm-tests/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->register(TestMessageHandler::class)
            ->addTag('messenger.message_handler');
        $container->register('logger', NullLogger::class);
        $container->addCompilerPass(new PublicBundleServicesPass());
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->services()
            ->defaults()->autowire()->autoconfigure()
            ->load('EasyAdminMessengerManager\\Tests\\App\\Admin\\', __DIR__.'/Admin/');

        $container->extension('framework', [
            'test' => true,
            'secret' => 'test',
            'http_method_override' => false,
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'router' => ['utf8' => true],
            'default_locale' => 'en',
            'translator' => ['fallbacks' => ['en']],
            'messenger' => [
                'failure_transport' => 'failed',
                'transports' => [
                    'async' => ['dsn' => 'in-memory://', 'retry_strategy' => ['max_retries' => 0]],
                    'failed' => ['dsn' => 'doctrine://default?queue_name=failed', 'retry_strategy' => ['max_retries' => 0]],
                    'pending' => ['dsn' => 'doctrine://default?queue_name=pending'],
                ],
                'routing' => [TestMessage::class => 'async'],
            ],
        ]);
        $container->extension('twig', ['default_path' => __DIR__.'/templates']);
        $container->extension('twig_component', ['anonymous_template_directory' => 'components/']);
        $container->extension('security', [
            'providers' => ['memory' => ['memory' => ['users' => [
                'admin' => ['password' => 'admin', 'roles' => ['ROLE_ADMIN']],
                'viewer' => ['password' => 'viewer', 'roles' => ['ROLE_VIEWER']],
            ]]]],
            'password_hashers' => ['Symfony\\Component\\Security\\Core\\User\\InMemoryUser' => 'plaintext'],
            'role_hierarchy' => ['ROLE_ADMIN' => ['ROLE_VIEWER']],
            'firewalls' => ['main' => ['lazy' => true, 'provider' => 'memory', 'logout' => ['path' => '/logout']]],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
            'orm' => ['auto_mapping' => false],
        ]);
        $container->extension('easy_admin_messenger_manager', [
            'history' => ['enabled' => true, 'capture_payload' => true],
            'permissions' => ['view' => 'ROLE_VIEWER', 'retry' => 'ROLE_ADMIN', 'remove' => 'ROLE_ADMIN'],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'easyadmin.routes');
        $routes->import('@LiveComponentBundle/config/routes.php')->prefix('/_components');
    }
}
