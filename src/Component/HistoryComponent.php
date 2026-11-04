<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Component;

use EasyAdminMessengerManager\Controller\Pagination;
use EasyAdminMessengerManager\History\HistoryFilter;
use EasyAdminMessengerManager\History\HistoryRepository;
use EasyAdminMessengerManager\Transport\TransportRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;

#[AsLiveComponent('EAMM:History', template: '@EasyAdminMessengerManager/components/history.html.twig')]
final class HistoryComponent extends AbstractController
{
    use DashboardLinks;
    use DefaultActionTrait;
    use GuardsViewAccess;

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: true)]
    public string $status = '';

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: new UrlMapping(as: 'class'))]
    public string $messageClass = '';

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: true)]
    public string $transport = '';

    #[LiveProp(writable: true, url: true)]
    public int $page = 1;

    #[LiveProp]
    public int $perPage = 50;

    #[LiveProp]
    public int $refreshSeconds = 10;

    public function __construct(
        private readonly HistoryRepository $history,
        private readonly TransportRegistry $transports,
    ) {
    }

    public function resetPage(): void
    {
        $this->page = 1;
    }

    #[LiveAction]
    public function goToPage(#[LiveArg] int $targetPage): void
    {
        $this->page = max(1, $targetPage);
    }

    public function getFilter(): HistoryFilter
    {
        return HistoryFilter::fromArray(['status' => $this->status, 'class' => $this->messageClass, 'transport' => $this->transport]);
    }

    public function getPagination(): Pagination
    {
        return Pagination::of($this->history->count($this->getFilter()), $this->page, $this->perPage);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getEntries(): array
    {
        $pagination = $this->getPagination();

        return $this->history->page($this->getFilter(), $pagination->page, $pagination->perPage);
    }

    /**
     * @return list<string>
     */
    public function getTransports(): array
    {
        return $this->transports->names();
    }
}
