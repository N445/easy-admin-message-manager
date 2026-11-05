<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use EasyAdminMessengerManager\History\HistoryEntry;
use EasyAdminMessengerManager\History\HistoryRepository;
use EasyAdminMessengerManager\History\HistoryStatus;
use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\Source\MessageSources;
use EasyAdminMessengerManager\Tests\App\Admin\MessengerDashboardController;
use EasyAdminMessengerManager\Tests\App\Admin\TestDashboardController;
use EasyAdminMessengerManager\Tests\App\TestKernel;
use EasyAdminMessengerManager\Tests\App\TestMessage;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class DashboardTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private KernelBrowser $client;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
        $this->client->loginUser($this->admin());
    }

    public function testOverviewRendersAPollingLiveComponent(): void
    {
        $this->addFailed('one', 'Database is gone');

        $crawler = $this->client->request('GET', '/admin/messenger');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller~="live"][data-eamm-poll="10"]');
        self::assertSelectorExists('[data-eamm-poll-toggle][aria-label="Pause auto-refresh (every 10 s)"]');
        self::assertSelectorTextSame('.eamm-tabs a[aria-current="page"]', 'Overview');
        self::assertSelectorExists('[data-eamm-setup-alert="live"][hidden]');
        $names = $crawler->filter('.eamm-card-name span:first-child')->each(static fn ($node): string => $node->text());
        self::assertSame(['async', 'failed', 'pending', 'Handled', 'Retrying', 'Failed'], $names);
        self::assertSelectorTextContains('.eamm-card-failure', '1');
        self::assertSelectorTextContains('.eamm-breakdown', 'DomainException');
    }

    public function testFailedPageReadsItsFiltersFromTheUrl(): void
    {
        $this->addFailed('one', 'Database is gone');
        $this->addFailed('two', 'Timeout');

        $this->client->request('GET', '/admin/messenger/failed?exception=timeout');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table.eamm-messages tbody', 'Timeout');
        self::assertSelectorTextNotContains('table.eamm-messages tbody', 'Database is gone');
    }

    public function testDetailPageRendersTheActionsComponent(): void
    {
        $this->addFailed('one', 'Database is gone');

        $this->client->request('GET', '/admin/messenger/messages/failed/'.$this->failedIds()[0]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-live-name-value="EAMM:MessageActions"]');
        self::assertSelectorTextContains('body', 'Database is gone');
        self::assertSelectorTextNotContains('body', 'p4ss');
    }

    public function testLiveRemoveDeletesTheMessage(): void
    {
        $this->addFailed('one', 'Database is gone');

        $rendered = $this->failedComponent()->actingAs($this->admin())
            ->call('remove', ['transport' => 'failed', 'id' => $this->failedIds()[0]])
            ->render();

        self::assertSame([], $this->failedIds());
        self::assertStringContainsString('The message was removed.', (string) $rendered);
    }

    public function testLiveBatchRequeuesEverythingMatchingTheFilter(): void
    {
        $this->addFailed('one', 'Timeout');
        $this->addFailed('two', 'Timeout');
        $this->addFailed('three', 'Database is gone');

        $this->failedComponent()->actingAs($this->admin())
            ->set('exception', 'timeout')
            ->call('batch', ['actionName' => 'requeue', 'scope' => 'all', 'expectedCount' => 2]);

        self::assertCount(1, $this->failedIds());
        $async = self::getContainer()->get('messenger.transport.async');
        \assert($async instanceof InMemoryTransport);
        self::assertCount(2, $async->getSent());
    }

    public function testLiveSelectionThenRemove(): void
    {
        $this->addFailed('one', 'Timeout');
        $this->addFailed('two', 'Timeout');

        $component = $this->failedComponent()->actingAs($this->admin())->call('selectPage');
        self::assertCount(2, $component->component()->selected);
        $component->call('batch', ['actionName' => 'remove', 'scope' => 'selected']);

        self::assertSame([], $this->failedIds());
    }

    public function testViewersCannotRemoveMessages(): void
    {
        $this->addFailed('one', 'Timeout');
        $this->client->catchExceptions(false);

        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);

        $this->failedComponent()->actingAs(new InMemoryUser('viewer', 'viewer', ['ROLE_VIEWER']))
            ->call('remove', ['transport' => 'failed', 'id' => $this->failedIds()[0]]);
    }

    public function testRetryFromDetailRedirectsToTheList(): void
    {
        $this->addFailed('works', 'Timeout');

        $component = $this->createLiveComponent('EAMM:MessageActions', [
            'controller' => MessengerDashboardController::class,
            'dashboard' => TestDashboardController::class,
            'transport' => 'failed',
            'messageId' => $this->failedIds()[0],
        ], $this->client)->actingAs($this->admin());
        $component->call('retry');

        self::assertSame(302, $component->response()->getStatusCode());
        self::assertStringEndsWith('/admin/messenger/failed', (string) $component->response()->headers->get('Location'));
        self::assertSame([], $this->failedIds());
    }

    public function testMessagesTabPagesAPendingTransport(): void
    {
        foreach (range(1, 3) as $number) {
            self::getContainer()->get('messenger.transport.pending')->send(new Envelope(new TestMessage('pending-'.$number)));
        }

        $crawler = $this->client->request('GET', '/admin/messenger/messages?transport=pending');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.eamm-pill[aria-pressed="true"]', 'pending');
        self::assertSelectorNotExists('[role="tab"]');
        self::assertCount(3, $crawler->filter('table.eamm-messages tbody tr'));

        $this->client->click($crawler->filter('table.eamm-messages tbody a')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('json-viewer');
        self::assertSelectorNotExists('[data-live-name-value="EAMM:MessageActions"]');
    }

    public function testHeaderCheckboxTogglesTheWholePage(): void
    {
        $this->addFailed('one', 'Timeout');
        $this->addFailed('two', 'Timeout');

        $component = $this->failedComponent()->actingAs($this->admin())->call('togglePage');
        self::assertCount(2, $component->component()->selected);
        self::assertStringContainsString('checked', (string) $component->render()->crawler()->filter('thead input[type=checkbox]')->outerHtml());

        $component->call('togglePage');
        self::assertSame([], $component->component()->selected);
    }

    public function testHistoryEntryPageShowsPayloadAndTrace(): void
    {
        self::getContainer()->get(\Symfony\Component\Messenger\MessageBusInterface::class)->dispatch(new TestMessage('ko', true));
        $dispatcher = self::getContainer()->get(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class);
        $stop = new \Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener(1);
        $dispatcher->addSubscriber($stop);
        (new \Symfony\Component\Messenger\Worker(['async' => self::getContainer()->get('messenger.transport.async')], self::getContainer()->get('messenger.routable_message_bus'), $dispatcher))->run(['sleep' => 0]);
        $dispatcher->removeSubscriber($stop);

        $crawler = $this->client->request('GET', '/admin/messenger/history');
        $this->client->click($crawler->filter('table.datagrid tbody a')->first()->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('json-viewer');
        self::assertSelectorTextContains('pre.eamm-trace', 'TestMessageHandler');
    }

    public function testHiddenAndSyncTransportsStayOutOfTheDashboard(): void
    {
        $sent = self::getContainer()->get('messenger.transport.internal')->send(new Envelope(new TestMessage('internal')));
        $this->addFailed('one', 'Timeout');

        $this->client->request('GET', '/admin/messenger/messages/internal/'.$sent->last(TransportMessageIdStamp::class)?->getId());
        self::assertResponseStatusCodeSame(404);

        $crawler = $this->client->request('GET', '/admin/messenger/failed');
        self::assertSame(['', 'async', 'pending'], $crawler->filter('#eamm-origin option')->each(static fn ($node): string => (string) $node->attr('value')));

        $crawler = $this->client->request('GET', '/admin/messenger/messages');
        self::assertSame(['failed', 'pending'], $crawler->filter('.eamm-pill')->each(static fn ($node): string => trim((string) preg_replace('/\s+\S+$/', '', $node->text()))));
    }

    public function testHistoryPageRenders(): void
    {
        $this->client->request('GET', '/admin/messenger/history');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-live-name-value="EAMM:History"][data-eamm-poll="10"]');
        self::assertSelectorTextContains('table.datagrid tbody', 'No history entries.');
    }

    public function testFailedRowsUseDeclarativeConfirmationsAndLoadingStates(): void
    {
        $this->addFailed('one', str_repeat('Database is gone. ', 20));

        $crawler = $this->client->request('GET', '/admin/messenger/failed');

        self::assertSelectorNotExists('[onclick]');
        $runNow = $crawler->filter('table.eamm-messages tbody button[data-live-action-param="retry"]');
        self::assertSame('Run now', trim($runNow->text()));
        self::assertSame('Run this message now? Its handler runs again in this request, side effects included.', $runNow->attr('data-eamm-confirm'));
        self::assertSame('addAttribute(disabled)', $runNow->attr('data-loading'));
        self::assertSelectorExists('.eamm-table-wrap[data-loading="delay(300)|addClass(eamm-is-loading)"] table.eamm-messages');
        self::assertSame(trim(str_repeat('Database is gone. ', 20)), trim((string) $crawler->filter('.eamm-clamp')->attr('title')));
        self::assertSelectorExists('table.eamm-messages time[datetime][title$="s ago"]');
    }

    public function testPaginationRendersAccessibleLinks(): void
    {
        foreach (['one', 'two', 'three', 'four'] as $label) {
            $this->addFailed($label, 'Timeout');
        }

        $component = $this->createLiveComponent('EAMM:FailedMessages', [
            'controller' => MessengerDashboardController::class,
            'dashboard' => TestDashboardController::class,
            'perPage' => 1,
        ], $this->client)->actingAs($this->admin());
        self::assertSame(3, $component->set('page', '3')->component()->page);
        $crawler = $component->call('goToPage', ['targetPage' => 2])->render()->crawler();

        $nav = $crawler->filter('nav.eamm-pagination');
        self::assertSame('Pagination', $nav->attr('aria-label'));
        self::assertSame('2', trim($nav->filter('button[aria-current="page"][disabled]')->text()));
        self::assertSame(['First page', 'Previous page', 'Page 1', 'Page 3', 'Page 4', 'Next page', 'Last page'], $nav->filter('button:not([disabled])')->each(static fn ($node): string => (string) $node->attr('aria-label')));
        self::assertSame('on(change)|page', $nav->filter('input[type=number]')->attr('data-model'));
    }

    public function testNoticesUseThemedAlertsWithARoleMatchingTheirSeverity(): void
    {
        $this->addFailed('one', 'Timeout');
        $component = $this->failedComponent()->actingAs($this->admin());

        $success = $component->call('remove', ['transport' => 'failed', 'id' => $this->failedIds()[0]])->render()->crawler();
        self::assertSame('status', $success->filter('.alert.alert-success.eamm-notice')->attr('role'));
        self::assertCount(1, $success->filter('.eamm-notice [data-eamm-dismiss][aria-label="Close"]'));

        $failure = $component->call('retry', ['transport' => 'failed', 'id' => 'missing'])->render()->crawler();
        self::assertSame('alert', $failure->filter('.alert.alert-danger.eamm-notice')->attr('role'));
    }

    public function testDetailPageOffersCopyButtons(): void
    {
        $this->addFailed('one', 'Database is gone');

        $crawler = $this->client->request('GET', '/admin/messenger/messages/failed/'.$this->failedIds()[0]);

        $copied = $crawler->filter('[data-eamm-copy]')->each(static fn ($node): string => (string) $node->attr('data-eamm-copy'));
        self::assertContains(TestMessage::class, $copied);
        self::assertContains(\DomainException::class, $copied);
        self::assertContains($this->failedIds()[0], $copied);
        self::assertSelectorTextContains('body', 'Content');
    }

    public function testSingleActionsDropTheMessageFromTheSelection(): void
    {
        $this->addFailed('one', 'Timeout');
        $this->addFailed('two', 'Timeout');
        $component = $this->failedComponent()->actingAs($this->admin())->call('selectPage');

        $component->call('remove', ['transport' => 'failed', 'id' => $this->failedIds()[0]]);

        self::assertSame(['failed::'.$this->failedIds()[0]], $component->component()->selected);
    }

    public function testEmptyFailedListShowsOneReassuringMessage(): void
    {
        $crawler = $this->client->request('GET', '/admin/messenger/failed');

        self::assertSelectorNotExists('.eamm-breakdown');
        self::assertSelectorNotExists('.eamm-toolbar');
        self::assertSame(1, substr_count($crawler->filter('body')->text(), 'No failed messages.'));
    }

    public function testFilteredEmptyStateOffersAReset(): void
    {
        $this->addFailed('one', 'Timeout');

        $this->client->request('GET', '/admin/messenger/failed?exception=nothing-like-this');

        self::assertSelectorTextContains('table.eamm-messages tbody', 'No message matches these filters.');
        self::assertSelectorExists('table.eamm-messages tbody button[data-live-action-param="resetFilters"]');
    }

    public function testAllMatchingActionsNeedAFilterAndTheExactCount(): void
    {
        $this->addFailed('one', 'Timeout');
        $this->addFailed('two', 'Timeout');
        $this->addFailed('three', 'Database is gone');

        $this->client->request('GET', '/admin/messenger/failed');
        self::assertSelectorNotExists('[data-live-scope-param="all"]');

        $crawler = $this->client->request('GET', '/admin/messenger/failed?exception=timeout');
        $removeAll = $crawler->filter('button[data-live-scope-param="all"][data-live-action-name-param="remove"]');
        self::assertSame('2', $removeAll->attr('data-eamm-confirm-type'));
        self::assertSame('2', $removeAll->attr('data-live-expected-count-param'));
        self::assertStringContainsString('matching Exception = timeout', (string) $removeAll->attr('data-eamm-confirm'));

        $component = $this->failedComponent()->actingAs($this->admin())->set('exception', 'timeout');
        $refused = $component->call('batch', ['actionName' => 'remove', 'scope' => 'all', 'expectedCount' => 5])->render()->crawler();
        self::assertStringContainsString('2 messages match now instead of 5', $refused->filter('.alert-warning')->text());
        self::assertCount(3, $this->failedIds());

        $unfiltered = $this->failedComponent()->actingAs($this->admin())->call('batch', ['actionName' => 'remove', 'scope' => 'all', 'expectedCount' => 3]);
        self::assertStringContainsString('Set a filter', $unfiltered->render()->crawler()->filter('.alert-warning')->text());
        self::assertCount(3, $this->failedIds());
    }

    public function testBatchNoticeTellsWhatHappenedAndLinksToHistory(): void
    {
        $this->addFailed('one', 'Timeout');
        $this->addFailed('two', 'Timeout');

        $crawler = $this->failedComponent()->actingAs($this->admin())
            ->call('selectPage')
            ->call('batch', ['actionName' => 'remove', 'scope' => 'selected'])
            ->render()->crawler();

        self::assertStringContainsString('2 messages removed.', $crawler->filter('.alert-success')->text());
        self::assertStringEndsWith('/admin/messenger/history', (string) $crawler->filter('.alert-success a')->attr('href'));
    }

    public function testDatesAcceptMinutesAndPresets(): void
    {
        $this->client->request('GET', '/admin/messenger/failed?from=2026-03-10');

        self::assertSelectorExists('input[type="datetime-local"]#eamm-from[value="2026-03-10T00:00"]');

        $component = $this->failedComponent()->actingAs($this->admin())->call('preset', ['range' => 'hour']);
        self::assertSame((new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i'), $component->component()->from);
        self::assertSame(['Last hour', 'Last 24 h', 'Last 7 days'], $component->render()->crawler()->filter('.eamm-presets button')->each(static fn ($node): string => trim($node->text())));
    }

    public function testBreakdownSetsOneFilterAndShowsRemovableChips(): void
    {
        $this->addFailed('one', 'Album 12 not found');
        $this->addFailed('two', 'Album 13 not found');

        $initial = $this->client->request('GET', '/admin/messenger/failed');
        self::assertSelectorExists('details.eamm-breakdown-panel[open]');
        self::assertContains('By error message', $initial->filter('.eamm-breakdown th')->each(static fn ($node): string => $node->text()));
        $patternButton = $initial->filter('.eamm-breakdown button[data-live-value-param="Album"]');
        self::assertSame('Album # not found', trim($patternButton->text()));

        $component = $this->failedComponent()->actingAs($this->admin())->set('origin', 'async')
            ->call('filterBy', ['field' => 'exception', 'value' => 'Album']);
        $crawler = $component->render()->crawler();

        self::assertSame('async', $component->component()->origin);
        self::assertSame('Album', $component->component()->exception);
        self::assertSame(['origin', 'exception'], $crawler->filter('.eamm-chip button')->each(static fn ($node): string => (string) $node->attr('data-live-field-param')));
        self::assertCount(0, $crawler->filter('details.eamm-breakdown-panel[open]'));

        $component->call('clearFilter', ['field' => 'origin']);
        self::assertSame('', $component->component()->origin);
        self::assertSame('Album', $component->component()->exception);
    }

    public function testFailedAtSortCanBeReversed(): void
    {
        $this->addFailed('first', 'Timeout');
        $this->addFailed('second', 'Timeout');
        $component = $this->failedComponent()->actingAs($this->admin());
        self::assertSame('second', $component->component()->getViews()[0]->envelope->getMessage()->label);

        $component->call('toggleSort');

        self::assertSame('asc', $component->component()->sort);
        self::assertSame('first', $component->component()->getViews()[0]->envelope->getMessage()->label);
        self::assertSame('ascending', $component->render()->crawler()->filter('th[aria-sort]')->attr('aria-sort'));
    }

    public function testSelectionSummaryAndPartialPageSelection(): void
    {
        foreach (['one', 'two', 'three'] as $label) {
            $this->addFailed($label, 'Timeout');
        }
        $component = $this->createLiveComponent('EAMM:FailedMessages', [
            'controller' => MessengerDashboardController::class,
            'dashboard' => TestDashboardController::class,
            'perPage' => 2,
        ], $this->client)->actingAs($this->admin());

        $crawler = $component->call('selectPage')->call('goToPage', ['targetPage' => 2])->render()->crawler();
        self::assertStringContainsString('2 selected', $crawler->filter('.eamm-toolbar')->text());
        self::assertStringContainsString('(2 on other pages)', $crawler->filter('.eamm-toolbar')->text());

        $component->call('goToPage', ['targetPage' => 1])->set('selected', ['failed::'.$this->failedIds()[0]]);
        self::assertSame('', $component->render()->crawler()->filter('thead input[type=checkbox]')->attr('data-eamm-indeterminate'));

        $cleared = $component->set('exception', 'timeout')->render()->crawler();
        self::assertSame([], $component->component()->selected);
        self::assertStringContainsString('The selection was cleared', $cleared->filter('.alert-warning')->text());
    }

    public function testRowControlsHaveAccessibleNames(): void
    {
        $this->addFailed('one', 'Timeout');
        $id = $this->failedIds()[0];

        $crawler = $this->client->request('GET', '/admin/messenger/failed');

        self::assertSame('Select TestMessage #'.$id, $crawler->filter('tbody input[type=checkbox]')->attr('aria-label'));
        self::assertSame('Run TestMessage #'.$id.' now', $crawler->filter('tbody button[data-live-action-param="retry"]')->attr('aria-label'));
        self::assertSame('Remove TestMessage #'.$id, $crawler->filter('tbody button[data-live-action-param="remove"]')->attr('aria-label'));
    }

    public function testListLinksCarryTheirReturnUrlToTheDetail(): void
    {
        $this->addFailed('works', 'Timeout');
        $id = $this->failedIds()[0];

        $crawler = $this->client->request('GET', '/admin/messenger/failed?exception=timeout');
        $this->client->click($crawler->filter('table.eamm-messages tbody a')->link());

        self::assertResponseIsSuccessful();
        self::assertSame('/admin/messenger/failed?exception=timeout', $this->client->getCrawler()->filter('a.btn:contains("Back to the list")')->attr('href'));

        $component = $this->createLiveComponent('EAMM:MessageActions', [
            'controller' => MessengerDashboardController::class,
            'dashboard' => TestDashboardController::class,
            'transport' => 'failed',
            'messageId' => $id,
            'returnTo' => '/admin/messenger/failed?exception=timeout',
        ], $this->client)->actingAs($this->admin());
        $component->call('retry');

        self::assertSame('/admin/messenger/failed?exception=timeout', $component->response()->headers->get('Location'));
    }

    public function testForeignReturnUrlsAreIgnored(): void
    {
        $this->addFailed('one', 'Timeout');
        $id = $this->failedIds()[0];

        foreach (['https://evil.example/admin/messenger/failed', '//evil.example/admin/messenger/failed', '/admin/messengerevil', '/elsewhere?to=/admin/messenger'] as $returnTo) {
            $crawler = $this->client->request('GET', '/admin/messenger/messages/failed/'.$id.'?returnTo='.rawurlencode($returnTo));

            self::assertResponseIsSuccessful();
            self::assertStringEndsWith('/admin/messenger/failed', (string) $crawler->filter('[data-live-name-value="EAMM:MessageActions"] a.btn')->attr('href'));
            self::assertStringNotContainsString('evil', (string) $this->client->getResponse()->getContent());
        }
    }

    public function testDetailPageShowsTheErrorThenPayloadThenCollapsedDetails(): void
    {
        $this->addFailed('one', 'Database is gone');

        $crawler = $this->client->request('GET', '/admin/messenger/messages/failed/'.$this->failedIds()[0]);

        self::assertSelectorTextContains('.alert-danger.eamm-notice', 'Database is gone');
        self::assertSelectorExists('a[href$="/admin/messenger/failed?class='.rawurlencode(TestMessage::class).'"]');
        self::assertSelectorExists('a[href$="/admin/messenger/failed?exception=DomainException"]');
        self::assertSelectorExists('a[href$="/admin/messenger/failed?origin=async"]');
        self::assertCount(3, $crawler->filter('[data-live-name-value="EAMM:SimilarFailures"]'));
        $summaries = $crawler->filter('details.eamm-section:not([open]) > summary');
        self::assertCount(2, $summaries);
        self::assertStringStartsWith('Stack trace', trim($summaries->eq(0)->text()));
        self::assertSame('Stamps', trim($summaries->eq(1)->text()));
        self::assertMatchesRegularExpression('#^tests/Functional/DashboardTest\.php:\d+$#', $summaries->eq(0)->filter('.eamm-frame .eamm-mono')->text());
        self::assertGreaterThan(strpos((string) $this->client->getResponse()->getContent(), 'eamm-error-message'), strpos((string) $this->client->getResponse()->getContent(), '<json-viewer'));
    }

    public function testSimilarFailuresCountsMatchingMessages(): void
    {
        $this->addFailed('one', 'Timeout');
        $this->addFailed('two', 'Timeout');

        $crawler = $this->createLiveComponent('EAMM:SimilarFailures', [
            'controller' => MessengerDashboardController::class,
            'dashboard' => TestDashboardController::class,
            'field' => 'origin',
            'value' => 'async',
        ], $this->client)->actingAs($this->admin())->render()->crawler();

        self::assertSame('2 similar', trim($crawler->filter('a.eamm-similar')->text()));
        self::assertStringEndsWith('/admin/messenger/failed?origin=async', (string) $crawler->filter('a.eamm-similar')->attr('href'));
    }

    public function testLoadFailuresRenderARetryNoticeInsteadOfAnError(): void
    {
        self::getContainer()->get('doctrine')->getConnection()->executeStatement('DROP TABLE messenger_messages');

        $this->client->request('GET', '/admin/messenger/failed');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-danger', 'The data could not be loaded.');
        self::assertSelectorExists('.alert-danger button[data-action="live#$render"]');
    }

    public function testMessagesTabShowsQueueColumnsAndCarriesItsReturnUrl(): void
    {
        $this->sendPending('one');

        $crawler = $this->client->request('GET', '/admin/messenger/messages?transport=pending');

        $headers = $crawler->filter('table.eamm-messages thead th')->each(static fn ($node): string => trim($node->text()));
        self::assertSame(['Id', 'Message', 'Queued at', 'Age', 'Redeliveries', 'Label', ''], \array_slice($headers, 1));
        self::assertSelectorExists('table.eamm-messages tbody time[datetime]');
        $link = $crawler->filter('table.eamm-messages tbody a')->first();
        self::assertStringContainsString('returnTo=/admin/messenger/messages?transport%3Dpending', (string) $link->attr('href'));

        $this->client->click($link->link());
        self::assertSame('/admin/messenger/messages?transport=pending', $this->client->getCrawler()->filter('a.btn:contains("Back to the list")')->attr('href'));
    }

    public function testPendingMessagesCanBeRemovedOneByOneOrBySelection(): void
    {
        foreach (['one', 'two', 'three'] as $label) {
            $this->sendPending($label);
        }
        $ids = $this->pendingIds();
        $component = $this->messagesComponent()->actingAs($this->admin());

        $removed = $component->call('remove', ['transport' => 'pending', 'id' => $ids[0]])->render()->crawler();
        self::assertStringContainsString('1 message removed.', $removed->filter('.alert-success')->text());
        self::assertCount(2, $this->pendingIds());

        $component->set('selected', ['pending::'.$ids[1], 'pending::'.$ids[2]]);
        self::assertStringContainsString('2 selected', $component->render()->crawler()->filter('.eamm-toolbar')->text());
        $component->call('removeSelected');

        self::assertSame([], $this->pendingIds());
        self::assertSame([], $component->component()->selected);
    }

    public function testViewersCannotRemovePendingMessages(): void
    {
        $this->sendPending('one');
        $viewer = new InMemoryUser('viewer', 'viewer', ['ROLE_VIEWER']);
        $this->client->loginUser($viewer);

        $this->client->request('GET', '/admin/messenger/messages?transport=pending');
        self::assertSelectorNotExists('table.eamm-messages button[data-live-action-param="remove"]');
        self::assertSelectorNotExists('table.eamm-messages input[type=checkbox]');

        $this->client->catchExceptions(false);
        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $this->messagesComponent()->actingAs($viewer)->call('remove', ['transport' => 'pending', 'id' => $this->pendingIds()[0]]);
    }

    public function testOverviewOrdersSectionsAndLinksToFilteredLists(): void
    {
        $this->addFailed('one', 'Timeout');
        $this->consumeFailing();

        $crawler = $this->client->request('GET', '/admin/messenger');

        $titles = $crawler->filter('h2.eamm-section-title')->each(static fn ($node): string => trim($node->text()));
        self::assertSame(['Transports', 'Last 24 hours', 'Failed messages (2)'], $titles);
        $recent = $crawler->filter('.eamm-card-failure a')->first();
        self::assertSame('2 failed in the last 24 h', trim($recent->text()));
        self::assertMatchesRegularExpression('#/admin/messenger/failed\?from=\d{4}-\d\d-\d\dT\d\d(:|%3A)\d\d$#', (string) $recent->attr('href'));
        self::assertMatchesRegularExpression('#/admin/messenger/history\?from=[^&]+&status=failed$#', (string) $crawler->filter('a.eamm-card-link')->eq(2)->attr('href'));
        self::assertStringContainsString('class='.rawurlencode(TestMessage::class), (string) $crawler->filter('.eamm-table-wrap table a')->first()->attr('href'));
    }

    public function testOverviewFlagsQueuesWhoseOldestMessageWaitsTooLong(): void
    {
        $this->sendPending('old');
        self::getContainer()->get('doctrine')->getConnection()->executeStatement("UPDATE messenger_messages SET created_at = '2020-01-01 00:00:00'");

        $this->client->request('GET', '/admin/messenger');

        self::assertSelectorTextContains('.eamm-card-stuck .eamm-card-name', 'pending');
        self::assertSelectorTextContains('.eamm-card-stuck .eamm-card-age.is-stuck', 'Oldest: ');
        self::assertSelectorCount(1, '.eamm-card-stuck');
    }

    public function testOverviewShowsTheTrendSinceThePreviousRefresh(): void
    {
        $component = $this->createLiveComponent('EAMM:Overview', [
            'controller' => MessengerDashboardController::class,
            'dashboard' => TestDashboardController::class,
        ], $this->client)->actingAs($this->admin());
        self::assertCount(0, $component->render()->crawler()->filter('.eamm-trend'));

        $this->sendPending('one');
        $crawler = $component->refresh()->render()->crawler();

        self::assertSame('Up from 0 at the previous refresh', $crawler->filter('.eamm-trend.is-up')->attr('aria-label'));
    }

    public function testOverviewBreakdownKeepsTheTopFive(): void
    {
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot'] as $error) {
            $this->addFailed($error, $error.' failed');
        }

        $crawler = $this->client->request('GET', '/admin/messenger');

        $byMessage = $crawler->filter('.eamm-breakdown .eamm-table-wrap')->eq(3);
        self::assertCount(5, $byMessage->filter('tbody tr'));
        self::assertSame('See all 6', trim($byMessage->filter('a.eamm-see-all')->text()));
        self::assertCount(1, $crawler->filter('a.eamm-see-all'));
    }

    public function testHistoryFiltersAndPollingOnlyOnTheFirstPage(): void
    {
        $history = self::getContainer()->get(HistoryRepository::class);
        foreach ([10, 20, 3000] as $duration) {
            $history->insert(new HistoryEntry(TestMessage::class, 'async', HistoryStatus::Handled, 1, $duration, null, null, null));
        }
        $component = $this->createLiveComponent('EAMM:History', [
            'controller' => MessengerDashboardController::class,
            'dashboard' => TestDashboardController::class,
            'perPage' => 1,
        ], $this->client)->actingAs($this->admin());

        self::assertCount(0, $component->render()->crawler()->filter('[data-eamm-poll-paused]'));
        self::assertCount(1, $component->call('goToPage', ['targetPage' => 2])->render()->crawler()->filter('[data-eamm-poll-paused]'));

        $component->set('duration', '1000');
        self::assertSame(1, $component->component()->getPagination()->total);
        self::assertSame(['duration' => '1000'], $component->component()->getFilter()->toArray());
    }

    public function testDetailPageShowsTheTimelineOfTrackedMessages(): void
    {
        $this->consumeFailing();
        $view = $this->failedViews()[0];

        $crawler = $this->client->request('GET', '/admin/messenger/messages/failed/'.$view->id);

        self::assertSelectorTextContains('.eamm-summary', (string) $view->trackingId());
        self::assertCount(1, $crawler->filter('table.eamm-timeline tbody tr'));
        self::assertSelectorTextContains('table.eamm-timeline tbody', 'Failed');
        self::assertSelectorTextContains('table.eamm-timeline tbody', 'Handler failed for ko');

        $this->client->click($crawler->filter('table.eamm-timeline tbody a')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-live-name-value="EAMM:MessageLocator"]');
    }

    public function testUntrackedMessagesExplainWhyTheirTimelineIsEmpty(): void
    {
        $this->addFailed('one', 'Timeout');

        $this->client->request('GET', '/admin/messenger/messages/failed/'.$this->failedIds()[0]);

        self::assertSelectorTextContains('.eamm-timeline-empty', 'no tracking id');
        self::assertSelectorNotExists('table.eamm-timeline');
    }

    public function testMessageLocatorFindsTheFailedMessage(): void
    {
        $this->consumeFailing();
        $view = $this->failedViews()[0];

        $crawler = $this->createLiveComponent('EAMM:MessageLocator', [
            'controller' => MessengerDashboardController::class,
            'dashboard' => TestDashboardController::class,
            'trackingId' => (string) $view->trackingId(),
        ], $this->client)->actingAs($this->admin())->render()->crawler();

        self::assertStringEndsWith('/admin/messenger/messages/failed/'.$view->id, (string) $crawler->filter('a')->attr('href'));
    }

    public function testCustomizersAddColumnsActionsAndLabels(): void
    {
        $this->addFailed('custom', 'Timeout');

        $crawler = $this->client->request('GET', '/admin/messenger/failed');

        self::assertContains('Label', $crawler->filter('table.eamm-messages thead th')->each(static fn ($node): string => trim($node->text())));
        self::assertSame('Custom <label>', $crawler->filter('table.eamm-messages tbody td a')->first()->text());
        self::assertStringContainsString('Custom &lt;label&gt;', (string) $this->client->getResponse()->getContent());
        self::assertSame(['/app/custom', '/app/admin'], $crawler->filter('table.eamm-messages tbody a[href^="/app/"]')->each(static fn ($node): string => (string) $node->attr('href')));

        $this->client->loginUser(new InMemoryUser('viewer', 'viewer', ['ROLE_VIEWER']));
        $crawler = $this->client->request('GET', '/admin/messenger/messages/failed/'.$this->failedIds()[0]);
        self::assertSelectorTextContains('h1', 'Custom <label>');
        self::assertSame(['/app/custom'], $crawler->filter('a.btn[href^="/app/"]')->each(static fn ($node): string => (string) $node->attr('href')));
    }

    public function testVotersReceiveTheMessageViewAsSubject(): void
    {
        $this->addFailed('support', 'Timeout');
        $this->addFailed('other', 'Timeout');
        $viewer = new InMemoryUser('viewer', 'viewer', ['ROLE_VIEWER']);
        $this->client->loginUser($viewer);

        $crawler = $this->client->request('GET', '/admin/messenger/failed');

        $runNow = $crawler->filter('table.eamm-messages tbody button[data-live-action-param="retry"]');
        self::assertCount(1, $runNow);
        self::assertStringContainsString('support', (string) $runNow->closest('tr')?->text());
        $supportId = $this->failedIds()[1];
        $this->failedComponent()->actingAs($viewer)->call('retry', ['transport' => 'failed', 'id' => $supportId]);
        self::assertNotContains($supportId, $this->failedIds());
    }

    private function messagesComponent(): \Symfony\UX\LiveComponent\Test\TestLiveComponent
    {
        return $this->createLiveComponent('EAMM:Messages', [
            'controller' => MessengerDashboardController::class,
            'dashboard' => TestDashboardController::class,
            'hiddenTransports' => ['internal'],
        ], $this->client);
    }

    private function sendPending(string $label): void
    {
        self::getContainer()->get('messenger.transport.pending')->send(new Envelope(new TestMessage($label)));
    }

    /**
     * @return list<string>
     */
    private function pendingIds(): array
    {
        return array_map(static fn ($view): string => $view->id, self::getContainer()->get(MessageSources::class)->transport('pending')->page(new MessageFilter(), 1, 1000)->views);
    }

    private function consumeFailing(): void
    {
        self::getContainer()->get(\Symfony\Component\Messenger\MessageBusInterface::class)->dispatch(new TestMessage('ko', true));
        $dispatcher = self::getContainer()->get(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class);
        $stop = new \Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener(1);
        $dispatcher->addSubscriber($stop);
        (new \Symfony\Component\Messenger\Worker(['async' => self::getContainer()->get('messenger.transport.async')], self::getContainer()->get('messenger.routable_message_bus'), $dispatcher))->run(['sleep' => 0]);
        $dispatcher->removeSubscriber($stop);
    }

    /**
     * @return list<\EasyAdminMessengerManager\Message\MessageView>
     */
    private function failedViews(): array
    {
        return self::getContainer()->get(MessageSources::class)->failed()->page(new MessageFilter(), 1, 1000)->views;
    }

    private function failedComponent(): \Symfony\UX\LiveComponent\Test\TestLiveComponent
    {
        return $this->createLiveComponent('EAMM:FailedMessages', [
            'controller' => MessengerDashboardController::class,
            'dashboard' => TestDashboardController::class,
        ], $this->client);
    }

    private function admin(): InMemoryUser
    {
        return new InMemoryUser('admin', 'admin', ['ROLE_ADMIN']);
    }

    private function addFailed(string $label, string $error): void
    {
        self::getContainer()->get('messenger.transport.failed')->send((new Envelope(new TestMessage($label)))->with(
            new SentToFailureTransportStamp('async'),
            ErrorDetailsStamp::create(new \DomainException($error)),
            new RedeliveryStamp(2),
        ));
    }

    /**
     * @return list<string>
     */
    private function failedIds(): array
    {
        return array_map(static fn ($view): string => $view->id, self::getContainer()->get(MessageSources::class)->failed()->page(new MessageFilter(), 1, 1000)->views);
    }
}
