<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Integration;

use EasyAdminMessengerManager\Tests\App\TestKernel;
use EasyAdminMessengerManager\Transport\TransportRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Filesystem\Filesystem;

final class BundleConfigurationTest extends TestCase
{
    private ?TestKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        (new Filesystem())->remove((string) $this->kernel?->getCacheDir());
    }

    public function testUnknownExcludedHistoryClassIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"App\\\\Message\\\\Missing" is neither a class nor an interface.');

        $this->boot(['history' => ['exclude' => ['App\\Message\\Missing']]]);
    }

    public function testExcludedHistoryInterfacesAreAccepted(): void
    {
        $this->boot(['history' => ['exclude' => [\Stringable::class]]]);

        self::assertSame([\Stringable::class], $this->kernel?->getContainer()->getParameter('easy_admin_messenger_manager.history_exclude'));
    }

    public function testUnknownBatchTransportListsTheAvailableOnes(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The transport "asynk" configured in "easy_admin_messenger_manager.batch.transport" does not exist. Available transports: "async", "failed", "pending", "internal", "sync".');

        $this->boot(['batch' => ['transport' => 'asynk']]);
    }

    public function testSyncTransportsAreNotRegistered(): void
    {
        $this->boot(['batch' => ['transport' => 'async']]);

        self::assertNotContains('sync', $this->kernel?->getContainer()->get(TransportRegistry::class)->names());
    }

    /**
     * @param array<string, mixed> $bundleConfig
     */
    private function boot(array $bundleConfig): void
    {
        $this->kernel = new TestKernel('test', false, $bundleConfig);
        $this->kernel->boot();
    }
}
