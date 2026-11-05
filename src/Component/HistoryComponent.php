<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Component;

use EasyAdminMessengerManager\Controller\Pagination;
use EasyAdminMessengerManager\Controller\ReturnUrl;
use EasyAdminMessengerManager\History\HistoryFilter;
use EasyAdminMessengerManager\History\HistoryRepository;
use EasyAdminMessengerManager\Transport\TransportRegistry;
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

#[AsLiveComponent('EAMM:History', template: '@EasyAdminMessengerManager/components/history.html.twig')]
final class HistoryComponent extends AbstractController
{
    use DashboardLinks;
    use DefaultActionTrait;
    use GuardsViewAccess;
    use ReportsLoadFailures;

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: true)]
    public string $status = '';

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: new UrlMapping(as: 'class'))]
    public string $messageClass = '';

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: true)]
    public string $transport = '';

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: true)]
    public string $exception = '';

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: true)]
    public string $from = '';

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: true)]
    public string $to = '';

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: true)]
    public string $duration = '';

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: new UrlMapping(as: 'message'))]
    public string $messageId = '';

    #[LiveProp(writable: true, url: true)]
    public int $page = 1;

    #[LiveProp]
    public int $perPage = 50;

    #[LiveProp]
    public int $refreshSeconds = 10;

    public function __construct(
        private readonly HistoryRepository $history,
        private readonly TransportRegistry $transports,
        private readonly AdminUrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[PostMount]
    #[PreReRender]
    public function normalizeFilters(): void
    {
        $filter = $this->getFilter();
        $this->from = $filter->fromInput();
        $this->to = $filter->toInput();
        $this->duration = (string) $filter->minDurationMs;
    }

    public function resetPage(): void
    {
        $this->page = 1;
    }

    #[LiveAction]
    public function resetFilters(): void
    {
        $this->status = $this->messageClass = $this->transport = $this->exception = $this->from = $this->to = $this->duration = $this->messageId = '';
        $this->resetPage();
    }

    #[LiveAction]
    public function goToPage(#[LiveArg] int $targetPage): void
    {
        $this->page = max(1, $targetPage);
    }

    public function getFilter(): HistoryFilter
    {
        return HistoryFilter::fromArray([
            'status' => $this->status,
            'class' => $this->messageClass,
            'transport' => $this->transport,
            'exception' => $this->exception,
            'from' => $this->from,
            'to' => $this->to,
            'duration' => $this->duration,
            'message' => $this->messageId,
        ]);
    }

    public function getPagination(): Pagination
    {
        return $this->loadSafely(
            fn (): Pagination => Pagination::of($this->history->count($this->getFilter()), $this->page, $this->perPage),
            Pagination::of(0, 1, $this->perPage),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getEntries(): array
    {
        $pagination = $this->getPagination();
        if ($this->loadFailed) {
            return [];
        }

        return $this->loadSafely(fn (): array => $this->history->page($this->getFilter(), $pagination->page, $pagination->perPage), []);
    }

    public function getReturnTo(): string
    {
        $urls = $this->urls->unsetAll()->setDashboard($this->dashboard)->setController($this->controller)->setAction('history');
        foreach (array_filter([...$this->getFilter()->toArray(), 'page' => $this->page > 1 ? $this->page : null]) as $name => $value) {
            $urls->set($name, $value);
        }

        return ReturnUrl::relative($urls->generateUrl());
    }

    /**
     * @return list<string>
     */
    public function getTransports(): array
    {
        return $this->transports->names();
    }
}
