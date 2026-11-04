<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Component;

use EasyAdminMessengerManager\Batch\BatchAction;
use EasyAdminMessengerManager\Batch\BatchDispatcher;
use EasyAdminMessengerManager\Batch\MessageSelection;
use EasyAdminMessengerManager\Controller\Pagination;
use EasyAdminMessengerManager\Failed\ActionResult;
use EasyAdminMessengerManager\Failed\FailedMessageRemover;
use EasyAdminMessengerManager\Failed\FailedMessageRetrier;
use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\MessagePage;
use EasyAdminMessengerManager\Message\Source\MessagePages;
use EasyAdminMessengerManager\Message\Source\MessageSources;
use EasyAdminMessengerManager\Message\MessageView;
use EasyAdminMessengerManager\Overview\FailureBreakdown;
use EasyAdminMessengerManager\Security\Permission;
use EasyAdminMessengerManager\Transport\TransportException;
use EasyAdminMessengerManager\Transport\TransportRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;

#[AsLiveComponent('EAMM:FailedMessages', template: '@EasyAdminMessengerManager/components/failed_messages.html.twig')]
final class FailedMessagesComponent extends AbstractController
{
    use DashboardLinks;
    use DefaultActionTrait;
    use GuardsViewAccess;

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
    public int $page = 1;

    /** @var list<string> */
    #[LiveProp(writable: true)]
    public array $selected = [];

    #[LiveProp]
    public int $perPage = 50;

    public ?Notice $notice = null;

    private ?MessagePage $currentPage = null;

    public function __construct(
        private readonly MessageSources $sources,
        private readonly TransportRegistry $transports,
        private readonly FailedMessageRetrier $retrier,
        private readonly FailedMessageRemover $remover,
        private readonly BatchDispatcher $batchDispatcher,
    ) {
    }

    public function resetPage(): void
    {
        $this->page = 1;
        $this->selected = [];
    }

    #[LiveAction]
    public function resetFilters(): void
    {
        $this->messageClass = $this->origin = $this->exception = $this->from = $this->to = '';
        $this->resetPage();
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

    public function isPageSelected(): bool
    {
        $references = $this->pageReferences();

        return [] !== $references && [] === array_diff($references, $this->selected);
    }

    #[LiveAction]
    public function clearSelection(): void
    {
        $this->selected = [];
    }

    #[LiveAction]
    public function retry(#[LiveArg] string $transport, #[LiveArg] string $id): void
    {
        $this->denyAccessUnlessGranted(Permission::Retry->value);
        $result = $this->attempt(fn () => $this->retrier->retry($transport, $id));
        $this->notice = $result->succeeded ? Notice::success('eamm.flash.retried') : Notice::error((string) $result->error);
    }

    #[LiveAction]
    public function remove(#[LiveArg] string $transport, #[LiveArg] string $id): void
    {
        $this->denyAccessUnlessGranted(Permission::Remove->value);
        $result = $this->attempt(function () use ($transport, $id): ActionResult {
            $this->remover->remove($transport, $id);

            return ActionResult::success();
        });
        $this->notice = $result->succeeded ? Notice::success('eamm.flash.removed') : Notice::error((string) $result->error);
    }

    #[LiveAction]
    public function batch(#[LiveArg] string $actionName, #[LiveArg] string $scope): void
    {
        $batchAction = BatchAction::from($actionName);
        $this->denyAccessUnlessGranted(BatchAction::Remove === $batchAction ? Permission::Remove->value : Permission::Retry->value);
        $selection = 'all' === $scope ? $this->failed()->selection($this->getFilter()) : MessageSelection::fromReferences($this->selected);
        $count = $this->batchDispatcher->dispatch($batchAction, $selection);
        $this->selected = [];
        $this->currentPage = null;
        $this->notice = Notice::success('eamm.flash.batch', ['%count%' => $count]);
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
        return FailureBreakdown::of($this->failed()->sample($this->getFilter()));
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
        return array_values(array_filter($this->transports->names(), fn (string $name): bool => !$this->transports->isFailure($name)));
    }

    public function reference(MessageView $view): string
    {
        return MessageSelection::reference($view);
    }

    /**
     * @return list<string>
     */
    private function pageReferences(): array
    {
        return array_map($this->reference(...), $this->getViews());
    }

    /**
     * @param \Closure(): ActionResult $action
     */
    private function attempt(\Closure $action): ActionResult
    {
        try {
            $result = $action();
        } catch (TransportException $exception) {
            $result = ActionResult::failure($exception);
        }

        $this->currentPage = null;

        return $result;
    }

    private function currentPage(): MessagePage
    {
        return $this->currentPage ??= $this->failed()->page($this->getFilter(), $this->page, $this->perPage);
    }

    private function failed(): MessagePages
    {
        return $this->sources->failed();
    }
}
