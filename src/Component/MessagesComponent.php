<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Component;

use EasyAdminMessengerManager\Batch\MessageSelection;
use EasyAdminMessengerManager\Controller\Pagination;
use EasyAdminMessengerManager\Controller\ReturnUrl;
use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\MessageFinder;
use EasyAdminMessengerManager\Message\MessagePage;
use EasyAdminMessengerManager\Message\MessageView;
use EasyAdminMessengerManager\Message\PendingMessageRemover;
use EasyAdminMessengerManager\Message\Source\MessageSources;
use EasyAdminMessengerManager\Overview\QueueOverview;
use EasyAdminMessengerManager\Overview\TransportSummary;
use EasyAdminMessengerManager\Security\Permission;
use EasyAdminMessengerManager\Transport\TransportException;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsLiveComponent('EAMM:Messages', template: '@EasyAdminMessengerManager/components/messages.html.twig')]
final class MessagesComponent extends AbstractController
{
    use DashboardLinks;
    use DefaultActionTrait;
    use GuardsViewAccess;
    use ReportsLoadFailures;

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: true)]
    public string $transport = '';

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: new UrlMapping(as: 'class'))]
    public string $messageClass = '';

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: true)]
    public string $from = '';

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: true)]
    public string $to = '';

    #[LiveProp(writable: true, url: true)]
    public int $page = 1;

    /** @var list<string> */
    #[LiveProp(writable: true)]
    public array $selected = [];

    #[LiveProp]
    public int $perPage = 50;

    /** @var list<string> */
    #[LiveProp]
    public array $hiddenTransports = [];

    public ?Notice $notice = null;

    /** @var list<TransportSummary>|null */
    private ?array $summaries = null;

    private ?MessagePage $currentPage = null;

    public function __construct(
        private readonly MessageSources $sources,
        private readonly QueueOverview $overview,
        private readonly MessageFinder $finder,
        private readonly PendingMessageRemover $remover,
        private readonly AdminUrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[PostMount]
    #[PreReRender]
    public function normalizeDates(): void
    {
        $filter = $this->getFilter();
        $this->from = $filter->fromInput();
        $this->to = $filter->toInput();
    }

    public function resetPage(): void
    {
        $this->page = 1;
        $this->currentPage = null;
        $this->selected = [];
    }

    #[LiveAction]
    public function goToPage(#[LiveArg] int $targetPage): void
    {
        $this->page = max(1, $targetPage);
    }

    #[LiveAction]
    public function selectTransport(#[LiveArg] string $name): void
    {
        $this->transport = $name;
        $this->resetPage();
    }

    #[LiveAction]
    public function clearSelection(): void
    {
        $this->selected = [];
    }

    #[LiveAction]
    public function remove(#[LiveArg] string $transport, #[LiveArg] string $id): void
    {
        $this->conclude($this->removeOne($transport, $id), 1);
    }

    #[LiveAction]
    public function removeSelected(): void
    {
        $this->denyAccessUnlessGranted(Permission::Remove->value);
        $removed = 0;
        foreach (MessageSelection::fromReferences($this->selected)->idsByTransport() as $transport => $ids) {
            $removed += array_sum(array_map(fn (string $id): int => $this->removeOne($transport, $id), $ids));
        }

        $this->conclude($removed, \count($this->selected));
    }

    /**
     * @return list<TransportSummary>
     */
    public function getTransports(): array
    {
        return $this->summaries ??= array_values(array_filter(
            $this->overview->summaries($this->hiddenTransports),
            static fn (TransportSummary $summary): bool => $summary->listable,
        ));
    }

    public function getCurrentTransport(): ?string
    {
        $names = array_map(static fn (TransportSummary $summary): string => $summary->name, $this->getTransports());
        if (\in_array($this->transport, $names, true)) {
            return $this->transport;
        }

        $busy = array_filter($this->getTransports(), static fn (TransportSummary $summary): bool => $summary->count > 0);

        return (reset($busy) ?: null)?->name ?? $names[0] ?? null;
    }

    public function getFilter(): MessageFilter
    {
        return MessageFilter::fromArray(['class' => $this->messageClass, 'from' => $this->from, 'to' => $this->to]);
    }

    public function getPagination(): ?Pagination
    {
        return $this->currentPage()?->pagination;
    }

    /**
     * @return list<MessageView>
     */
    public function getViews(): array
    {
        return $this->currentPage()->views ?? [];
    }

    public function hasErrors(): bool
    {
        return [] !== array_filter($this->getViews(), static fn (MessageView $view): bool => null !== $view->exceptionClass);
    }

    public function isComplete(): bool
    {
        return $this->currentPage()->complete ?? true;
    }

    public function getReturnTo(): string
    {
        $urls = $this->urls->unsetAll()->setDashboard($this->dashboard)->setController($this->controller)->setAction('messages');
        foreach ($this->listParameters() as $name => $value) {
            $urls->set($name, $value);
        }

        return ReturnUrl::relative($urls->generateUrl());
    }

    public function reference(MessageView $view): string
    {
        return MessageSelection::reference($view);
    }

    /**
     * @return array<string, int|string>
     */
    private function listParameters(): array
    {
        return array_filter([
            'transport' => $this->getCurrentTransport(),
            ...$this->getFilter()->toArray(),
            'page' => $this->page > 1 ? $this->page : null,
        ], static fn (int|string|null $value): bool => null !== $value);
    }

    private function removeOne(string $transport, string $id): int
    {
        if (\in_array($transport, $this->hiddenTransports, true)) {
            throw $this->createNotFoundException(\sprintf('The transport "%s" is hidden.', $transport));
        }

        try {
            $this->denyAccessUnlessGranted(Permission::Remove->value, $this->finder->find($transport, $id));
            $this->remover->remove($transport, $id);
        } catch (TransportException $exception) {
            $this->logger->warning('Pending message {id} could not be removed: {error}', ['id' => $id, 'transport' => $transport, 'error' => $exception->getMessage()]);

            return 0;
        }

        return 1;
    }

    private function conclude(int $removed, int $requested): void
    {
        $this->selected = [];
        $this->currentPage = null;
        $this->summaries = null;
        $this->sources->forget();
        $this->notice = $removed === $requested
            ? Notice::success('eamm.flash.pending_removed', ['%count%' => $removed])
            : Notice::warning('eamm.flash.pending_partially_removed', ['%count%' => $removed, '%requested%' => $requested]);
    }

    private function currentPage(): ?MessagePage
    {
        $transport = $this->getCurrentTransport();
        if (null === $transport) {
            return null;
        }

        return $this->currentPage ??= $this->loadSafely(
            fn (): MessagePage => $this->sources->transport($transport)->page($this->getFilter(), $this->page, $this->perPage),
            new MessagePage([], Pagination::of(0, 1, $this->perPage), true),
        );
    }
}
