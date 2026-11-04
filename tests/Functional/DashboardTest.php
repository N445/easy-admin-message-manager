<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
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
        self::assertSelectorExists('[data-controller~="live"][data-poll]');
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
            ->call('batch', ['actionName' => 'requeue', 'scope' => 'all']);

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
        self::assertSelectorTextContains('.eamm-pill.is-active', 'pending');
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

    public function testHistoryPageRenders(): void
    {
        $this->client->request('GET', '/admin/messenger/history');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-live-name-value="EAMM:History"]');
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
