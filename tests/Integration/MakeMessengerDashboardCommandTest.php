<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Integration;

use EasyAdminMessengerManager\Command\MakeMessengerDashboardCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class MakeMessengerDashboardCommandTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/eamm-make-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testGeneratesTheControllerWithDefaults(): void
    {
        $tester = new CommandTester(new MakeMessengerDashboardCommand($this->projectDir));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $code = (string) file_get_contents($this->projectDir.'/src/Controller/Admin/MessengerDashboardController.php');
        self::assertStringContainsString('namespace App\Controller\Admin;', $code);
        self::assertStringContainsString("#[AdminRoute('/messenger', name: 'messenger')]", $code);
        self::assertStringContainsString('final class MessengerDashboardController extends AbstractMessengerDashboardController', $code);
        self::assertStringContainsString('MenuItem::linkTo(MessengerDashboardController::class', $tester->getDisplay());
    }

    public function testCustomNameDirectoryAndRoute(): void
    {
        $tester = new CommandTester(new MakeMessengerDashboardCommand($this->projectDir));

        $tester->execute(['class' => 'Queues', '--dir' => 'src/Admin/Tools', '--route' => 'Message Queues']);

        $code = (string) file_get_contents($this->projectDir.'/src/Admin/Tools/QueuesController.php');
        self::assertStringContainsString('namespace App\Admin\Tools;', $code);
        self::assertStringContainsString("#[AdminRoute('/message-queues', name: 'message_queues')]", $code);
    }

    public function testRefusesToOverwriteAnExistingFile(): void
    {
        $tester = new CommandTester(new MakeMessengerDashboardCommand($this->projectDir));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    public function testRejectsDirectoriesOutsideTheProject(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new CommandTester(new MakeMessengerDashboardCommand($this->projectDir)))->execute(['--dir' => '../evil']);
    }

    public function testGeneratedClassIsValidPhp(): void
    {
        (new CommandTester(new MakeMessengerDashboardCommand($this->projectDir)))->execute([]);

        exec('php -l '.escapeshellarg($this->projectDir.'/src/Controller/Admin/MessengerDashboardController.php'), $output, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $output));
    }
}
