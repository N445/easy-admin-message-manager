<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

use function Symfony\Component\String\u;

#[AsCommand(name: 'make:eamm:dashboard', description: 'Creates an EasyAdmin controller managing Symfony Messenger messages')]
final class MakeDashboardCommand extends Command
{
    private const string SKELETON = __DIR__.'/../Resources/skeleton/messenger_dashboard_controller.tpl';

    public function __construct(
        private readonly string $projectDir,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('class', InputArgument::OPTIONAL, 'Controller class name', 'MessengerDashboardController')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory relative to the project root', 'src/Controller/Admin')
            ->addOption('route', null, InputOption::VALUE_REQUIRED, 'Route path and name prefix inside the dashboard', 'messenger')
            ->setHelp(<<<'HELP'
                Generates the EasyAdmin controller serving the Messenger pages:

                  <info>php %command.full_name%</info>
                  <info>php %command.full_name% Queues --dir=src/Admin --route=queues</info>

                Pick another <comment>--route</comment> when <comment>/admin/messenger</comment> is already taken by another bundle.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $className = u((string) $input->getArgument('class'))->ensureEnd('Controller')->toString();
        $directory = $this->directory((string) $input->getOption('dir'));
        $route = u((string) $input->getOption('route'))->trim('/')->snake()->toString();
        $relativePath = $directory.'/'.$className.'.php';
        $absolutePath = $this->projectDir.'/'.$relativePath;

        if ($this->filesystem->exists($absolutePath)) {
            $io->error(\sprintf('"%s" already exists.', $relativePath));

            return Command::FAILURE;
        }

        $this->filesystem->dumpFile($absolutePath, $this->render($this->namespaceOf($directory), $className, $route));
        $io->success(\sprintf('Created "%s".', $relativePath));
        $io->text([
            'Add it to the menu of your dashboard controller:',
            '',
            \sprintf("    yield MenuItem::linkTo(%s::class, 'Messenger', 'fa fa-envelope')->setAction('overview');", $className),
            '',
            'Override its protected methods to customize the pages (messagesPerPage(), hiddenTransports(), ...).',
        ]);

        return Command::SUCCESS;
    }

    private function directory(string $directory): string
    {
        $directory = u($directory)->trim('/')->toString();
        if (str_contains($directory, '..')) {
            throw new \InvalidArgumentException('The directory must be relative to the project root and cannot contain "..".');
        }

        return $directory;
    }

    private function namespaceOf(string $directory): string
    {
        return u($directory)
            ->replaceMatches('#^src(/|$)#', 'App$1')
            ->replace('/', ' ')
            ->title(true)
            ->replace(' ', '\\')
            ->toString();
    }

    private function render(string $namespace, string $className, string $route): string
    {
        return strtr((string) file_get_contents(self::SKELETON), [
            '{{ namespace }}' => $namespace,
            '{{ class_name }}' => $className,
            '{{ route_path }}' => str_replace('_', '-', $route),
            '{{ route_name }}' => $route,
        ]);
    }
}
