<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Component;

use EasyAdminMessengerManager\History\HistoryRepository;
use EasyAdminMessengerManager\Message\DateInput;
use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\Source\MessageSources;
use EasyAdminMessengerManager\Overview\FailureBreakdown;
use EasyAdminMessengerManager\Overview\QueueOverview;
use EasyAdminMessengerManager\Overview\TransportSummary;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsLiveComponent('EAMM:Overview', template: '@EasyAdminMessengerManager/components/overview.html.twig')]
final class OverviewComponent extends AbstractController
{
    use DashboardLinks;
    use DefaultActionTrait;
    use GuardsViewAccess;
    use ReportsLoadFailures;

    public const int BREAKDOWN_LIMIT = 5;

    private const string RECENT_PERIOD = '-24 hours';

    /** @var list<string> */
    #[LiveProp]
    public array $hiddenTransports = [];

    #[LiveProp]
    public int $refreshSeconds = 10;

    #[LiveProp]
    public int $stuckAfterSeconds = 900;

    /** @var array<string, int> */
    #[LiveProp]
    public array $counts = [];

    /** @var array<string, int> */
    private array $previousCounts = [];

    /** @var list<TransportSummary>|null */
    private ?array $summaries = null;

    /** @var array<string, \DateTimeImmutable|null> */
    private array $oldest = [];

    public function __construct(
        private readonly QueueOverview $overview,
        private readonly MessageSources $sources,
        private readonly ?HistoryRepository $history = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[PostMount]
    #[PreReRender]
    public function rememberCounts(): void
    {
        $this->previousCounts = $this->counts;
        $this->counts = array_column(
            array_filter($this->getTransports(), static fn (TransportSummary $summary): bool => null !== $summary->count),
            'count',
            'name',
        );
    }

    /**
     * @return list<TransportSummary>
     */
    public function getTransports(): array
    {
        return $this->summaries ??= $this->overview->summaries($this->hiddenTransports);
    }

    public function trend(TransportSummary $summary): ?string
    {
        $previous = $this->previousCounts[$summary->name] ?? null;
        if (null === $previous || null === $summary->count || $previous === $summary->count) {
            return null;
        }

        return $summary->count > $previous ? 'up' : 'down';
    }

    public function previousCount(TransportSummary $summary): ?int
    {
        return $this->previousCounts[$summary->name] ?? null;
    }

    public function oldestQueuedAt(TransportSummary $summary): ?\DateTimeImmutable
    {
        if ($summary->failure || !$summary->listable || !$summary->count) {
            return null;
        }

        if (!\array_key_exists($summary->name, $this->oldest)) {
            $this->oldest[$summary->name] = $this->loadSafely(fn (): ?\DateTimeImmutable => $this->sources->transport($summary->name)->oldestQueuedAt(), null);
        }

        return $this->oldest[$summary->name];
    }

    public function isStuck(TransportSummary $summary): bool
    {
        $oldest = $this->oldestQueuedAt($summary);

        return $this->stuckAfterSeconds > 0 && null !== $oldest && time() - $oldest->getTimestamp() > $this->stuckAfterSeconds;
    }

    public function getFailedTotal(): int
    {
        return $this->loadSafely(fn (): int => $this->sources->failed()->count(new MessageFilter()), 0);
    }

    public function getRecentFailures(): int
    {
        return $this->loadSafely(fn (): int => $this->sources->failed()->count(new MessageFilter(from: $this->recentPeriodStart())), 0);
    }

    public function getSince(): string
    {
        return DateInput::input($this->recentPeriodStart());
    }

    public function getBreakdown(): FailureBreakdown
    {
        return $this->loadSafely(fn (): FailureBreakdown => FailureBreakdown::of($this->sources->failed()->sample(new MessageFilter())), FailureBreakdown::of([]));
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
        if (null === $this->history) {
            return null;
        }

        return $this->loadSafely(fn (): array => [
            'statuses' => $this->history->statusCounts($this->recentPeriodStart()),
            'classes' => $this->history->classStats($this->recentPeriodStart(), 10),
        ], null);
    }

    private function recentPeriodStart(): \DateTimeImmutable
    {
        $since = new \DateTimeImmutable(self::RECENT_PERIOD);

        return $since->setTime((int) $since->format('H'), (int) $since->format('i'));
    }
}
