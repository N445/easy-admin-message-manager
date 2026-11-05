<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Component;

use EasyAdminMessengerManager\Batch\BatchAction;
use EasyAdminMessengerManager\Batch\BatchDispatcher;
use EasyAdminMessengerManager\Batch\BatchReceipt;
use EasyAdminMessengerManager\Batch\MessageSelection;
use EasyAdminMessengerManager\Controller\Pagination;
use EasyAdminMessengerManager\Controller\ReturnUrl;
use EasyAdminMessengerManager\Failed\ActionResult;
use EasyAdminMessengerManager\Failed\FailedMessageRemover;
use EasyAdminMessengerManager\Failed\FailedMessageRetrier;
use EasyAdminMessengerManager\History\HistoryRepository;
use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\MessageFinder;
use EasyAdminMessengerManager\Message\MessageOrder;
use EasyAdminMessengerManager\Message\MessagePage;
use EasyAdminMessengerManager\Message\MessageView;
use EasyAdminMessengerManager\Message\Source\MessagePages;
use EasyAdminMessengerManager\Message\Source\MessageSources;
use EasyAdminMessengerManager\Overview\FailureBreakdown;
use EasyAdminMessengerManager\Security\Permission;
use EasyAdminMessengerManager\Transport\TransportException;
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
use Symfony\UX\TwigComponent\Attribute\PostMount;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;

#[AsLiveComponent('EAMM:FailedMessages', template: '@EasyAdminMessengerManager/components/failed_messages.html.twig')]
final class FailedMessagesComponent extends AbstractController
{
    use DashboardLinks;
    use DefaultActionTrait;
    use GuardsViewAccess;
    use ReportsLoadFailures;

    /** @var array<string, string> */
    private const array FILTER_PROPERTIES = ['class' => 'messageClass', 'origin' => 'origin', 'exception' => 'exception', 'from' => 'from', 'to' => 'to'];

    /** @var array<string, string> */
    private const array PRESETS = ['hour' => '-1 hour', 'day' => '-24 hours', 'week' => '-7 days'];

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: new UrlMapping(as: 'class'))]
    public string $messageClass = '';

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: true)]
    public string $origin = '';

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: true)]
    public string $exception = '';

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: true)]
    public string $from = '';

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: true)]
    public string $to = '';

    #[LiveProp(writable: true, url: true)]
    public string $sort = '';

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

    private ?MessagePage $currentPage = null;

    private ?FailureBreakdown $breakdown = null;

    public function __construct(
        private readonly MessageSources $sources,
        private readonly TransportRegistry $transports,
        private readonly FailedMessageRetrier $retrier,
        private readonly FailedMessageRemover $remover,
        private readonly BatchDispatcher $batchDispatcher,
        private readonly AdminUrlGeneratorInterface $urls,
        private readonly MessageFinder $finder,
        private readonly ?HistoryRepository $history = null,
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
        $this->forgetLoadedData();
        if ([] !== $this->selected) {
            $this->notice = Notice::warning('eamm.batch.selection_cleared');
        }
        $this->selected = [];
    }

    #[LiveAction]
    public function resetFilters(): void
    {
        $this->messageClass = $this->origin = $this->exception = $this->from = $this->to = '';
        $this->resetPage();
    }

    #[LiveAction]
    public function filterBy(#[LiveArg] string $field, #[LiveArg] string $value): void
    {
        $property = self::FILTER_PROPERTIES[$field] ?? null;
        if (null === $property) {
            return;
        }

        $this->{$property} = trim($value);
        $this->resetPage();
    }

    #[LiveAction]
    public function clearFilter(#[LiveArg] string $field): void
    {
        $this->filterBy($field, '');
    }

    #[LiveAction]
    public function preset(#[LiveArg] string $range): void
    {
        $modifier = self::PRESETS[$range] ?? null;
        if (null === $modifier) {
            return;
        }

        $this->from = (new \DateTimeImmutable($modifier))->format('Y-m-d\TH:i');
        $this->to = '';
        $this->resetPage();
    }

    #[LiveAction]
    public function toggleSort(): void
    {
        $this->sort = $this->getOrder()->reversed()->value;
        $this->page = 1;
        $this->forgetLoadedData();
    }

    #[LiveAction]
    public function goToPage(#[LiveArg] int $targetPage): void
    {
        $this->page = max(1, $targetPage);
    }

    #[LiveAction]
    public function selectPage(): void
    {
        $this->selected = array_values(array_unique([...$this->selected, ...$this->pageReferences()]));
    }

    #[LiveAction]
    public function togglePage(): void
    {
        if ($this->isPageSelected()) {
            $this->selected = array_values(array_diff($this->selected, $this->pageReferences()));

            return;
        }

        $this->selectPage();
    }

    #[LiveAction]
    public function clearSelection(): void
    {
        $this->selected = [];
    }

    #[LiveAction]
    public function retry(#[LiveArg] string $transport, #[LiveArg] string $id): void
    {
        $this->denyAccessUnlessGranted(Permission::Retry->value, $this->viewOf($transport, $id));
        $result = $this->attempt($transport, $id, fn () => $this->retrier->retry($transport, $id));
        $this->notice = $result->succeeded ? Notice::success('eamm.flash.retried') : Notice::error((string) $result->error);
    }

    #[LiveAction]
    public function remove(#[LiveArg] string $transport, #[LiveArg] string $id): void
    {
        $this->denyAccessUnlessGranted(Permission::Remove->value, $this->viewOf($transport, $id));
        $result = $this->attempt($transport, $id, function () use ($transport, $id): ActionResult {
            $this->remover->remove($transport, $id);

            return ActionResult::success();
        });
        $this->notice = $result->succeeded ? Notice::success('eamm.flash.removed') : Notice::error((string) $result->error);
    }

    #[LiveAction]
    public function batch(#[LiveArg] string $actionName, #[LiveArg] string $scope, #[LiveArg] int $expectedCount = 0): void
    {
        $batchAction = BatchAction::from($actionName);
        $this->denyAccessUnlessGranted(BatchAction::Remove === $batchAction ? Permission::Remove->value : Permission::Retry->value);
        if ('all' === $scope) {
            $this->batchAllMatching($batchAction, $expectedCount);

            return;
        }

        $this->conclude($this->batchDispatcher->dispatch($batchAction, MessageSelection::fromReferences($this->selected)));
    }

    public function getFilter(): MessageFilter
    {
        return MessageFilter::fromArray([
            'class' => $this->messageClass,
            'origin' => $this->origin,
            'exception' => $this->exception,
            'from' => $this->from,
            'to' => $this->to,
        ]);
    }

    public function getOrder(): MessageOrder
    {
        return MessageOrder::fromQuery($this->sort);
    }

    public function getPagination(): Pagination
    {
        return $this->currentPage()->pagination;
    }

    /**
     * @return list<MessageView>
     */
    public function getViews(): array
    {
        return $this->currentPage()->views;
    }

    public function getBreakdown(): FailureBreakdown
    {
        return $this->breakdown ??= $this->loadSafely(
            fn (): FailureBreakdown => FailureBreakdown::of($this->failed()->sample($this->getFilter())),
            FailureBreakdown::of([]),
        );
    }

    public function getSampleSize(): int
    {
        return $this->failed()->sampleSize();
    }

    /**
     * @return list<string>
     */
    public function getOrigins(): array
    {
        return array_values(array_filter(
            array_diff($this->transports->names(), $this->hiddenTransports),
            fn (string $name): bool => !$this->transports->isFailure($name),
        ));
    }

    public function isPageSelected(): bool
    {
        $references = $this->pageReferences();

        return [] !== $references && [] === array_diff($references, $this->selected);
    }

    public function isPagePartiallySelected(): bool
    {
        return !$this->isPageSelected() && [] !== array_intersect($this->pageReferences(), $this->selected);
    }

    public function getSelectedOnOtherPages(): int
    {
        return \count(array_diff($this->selected, $this->pageReferences()));
    }

    public function getReturnTo(): string
    {
        $urls = $this->urls->unsetAll()->setDashboard($this->dashboard)->setController($this->controller)->setAction('failed');
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
            ...$this->getFilter()->toArray(),
            'sort' => MessageOrder::OldestFirst === $this->getOrder() ? $this->getOrder()->value : null,
            'page' => $this->page > 1 ? $this->page : null,
        ], static fn (int|string|null $value): bool => null !== $value);
    }

    private function batchAllMatching(BatchAction $batchAction, int $expectedCount): void
    {
        $filter = $this->getFilter();
        if ($filter->isEmpty()) {
            $this->notice = Notice::warning('eamm.batch.filter_required');

            return;
        }

        $count = $this->failed()->count($filter);
        if ($count !== $expectedCount) {
            $this->notice = Notice::warning('eamm.batch.count_changed', ['%expected%' => $expectedCount, '%count%' => $count]);

            return;
        }

        $this->conclude($this->batchDispatcher->dispatch($batchAction, $this->failed()->selection($filter)));
    }

    private function conclude(BatchReceipt $receipt): void
    {
        $this->selected = [];
        $this->forgetLoadedData();
        $this->notice = $this->withHistoryLink(Notice::success(
            \sprintf('eamm.flash.batch_%s_%s', $receipt->isQueued() ? 'queued' : 'done', $receipt->action->value),
            ['%count%' => $receipt->count, '%transport%' => (string) $receipt->transport],
        ));
    }

    private function withHistoryLink(Notice $notice): Notice
    {
        if (null === $this->history) {
            return $notice;
        }

        $url = $this->urls->unsetAll()->setDashboard($this->dashboard)->setController($this->controller)->setAction('history')->generateUrl();

        return $notice->withLink($url, 'eamm.batch.see_history');
    }

    /**
     * @return list<string>
     */
    private function pageReferences(): array
    {
        return array_map($this->reference(...), $this->getViews());
    }

    private function viewOf(string $transport, string $id): ?MessageView
    {
        try {
            return $this->finder->find($transport, $id);
        } catch (TransportException) {
            return null;
        }
    }

    /**
     * @param \Closure(): ActionResult $action
     */
    private function attempt(string $transport, string $id, \Closure $action): ActionResult
    {
        try {
            $result = $action();
        } catch (TransportException $exception) {
            $result = ActionResult::failure($exception);
        }

        $this->selected = array_values(array_diff($this->selected, [MessageSelection::referenceOf($transport, $id)]));
        $this->forgetLoadedData();

        return $result;
    }

    private function forgetLoadedData(): void
    {
        $this->currentPage = null;
        $this->breakdown = null;
    }

    private function currentPage(): MessagePage
    {
        return $this->currentPage ??= $this->loadSafely(
            fn (): MessagePage => $this->failed()->page($this->getFilter(), $this->page, $this->perPage, $this->getOrder()),
            new MessagePage([], Pagination::of(0, 1, $this->perPage), true),
        );
    }

    private function failed(): MessagePages
    {
        return $this->sources->failed();
    }
}
