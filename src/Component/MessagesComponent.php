<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Component;

use EasyAdminMessengerManager\Controller\Pagination;
use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\MessagePage;
use EasyAdminMessengerManager\Message\MessageView;
use EasyAdminMessengerManager\Message\Source\MessageSources;
use EasyAdminMessengerManager\Overview\QueueOverview;
use EasyAdminMessengerManager\Overview\TransportSummary;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;

#[AsLiveComponent('EAMM:Messages', template: '@EasyAdminMessengerManager/components/messages.html.twig')]
final class MessagesComponent extends AbstractController
{
    use DashboardLinks;
    use DefaultActionTrait;
    use GuardsViewAccess;

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

    #[LiveProp]
    public int $perPage = 50;

    /** @var list<string> */
    #[LiveProp]
    public array $hiddenTransports = [];

    /** @var list<TransportSummary>|null */
    private ?array $summaries = null;

    private ?MessagePage $currentPage = null;

    public function __construct(
        private readonly MessageSources $sources,
        private readonly QueueOverview $overview,
    ) {
    }

    public function resetPage(): void
    {
        $this->page = 1;
        $this->currentPage = null;
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

    public function isComplete(): bool
    {
        return $this->currentPage()->complete ?? true;
    }

    private function currentPage(): ?MessagePage
    {
        $transport = $this->getCurrentTransport();
        if (null === $transport) {
            return null;
        }

        return $this->currentPage ??= $this->sources->transport($transport)->page($this->getFilter(), $this->page, $this->perPage);
    }
}
