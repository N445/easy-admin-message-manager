<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Component;

use EasyAdminMessengerManager\History\HistoryRepository;
use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\Source\MessageSources;
use EasyAdminMessengerManager\Overview\FailureBreakdown;
use EasyAdminMessengerManager\Overview\QueueOverview;
use EasyAdminMessengerManager\Overview\TransportSummary;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('EAMM:Overview', template: '@EasyAdminMessengerManager/components/overview.html.twig')]
final class OverviewComponent extends AbstractController
{
    use DashboardLinks;
    use DefaultActionTrait;
    use GuardsViewAccess;

    /** @var list<string> */
    #[LiveProp]
    public array $hiddenTransports = [];

    #[LiveProp]
    public int $refreshSeconds = 10;

    public function __construct(
        private readonly QueueOverview $overview,
        private readonly MessageSources $sources,
        private readonly ?HistoryRepository $history = null,
    ) {
    }

    /**
     * @return list<TransportSummary>
     */
    public function getTransports(): array
    {
        return $this->overview->summaries($this->hiddenTransports);
    }

    public function getFailedTotal(): int
    {
        return $this->sources->failed()->count(new MessageFilter());
    }

    public function getBreakdown(): FailureBreakdown
    {
        return FailureBreakdown::of($this->sources->failed()->sample(new MessageFilter()));
    }

    public function getSampleSize(): int
    {
        return $this->sources->failed()->sampleSize();
    }

    /**
     * @return array{statuses: array<string, int>, classes: list<array<string, mixed>>}|null
     */
    public function getHistoryStats(): ?array
    {
        $since = new \DateTimeImmutable('-24 hours');

        return null === $this->history ? null : [
            'statuses' => $this->history->statusCounts($since),
            'classes' => $this->history->classStats($since, 10),
        ];
    }
}
