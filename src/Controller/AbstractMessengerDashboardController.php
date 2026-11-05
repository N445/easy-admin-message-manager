<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Controller;

use EasyAdminMessengerManager\History\HistoryRepository;
use EasyAdminMessengerManager\Message\ApplicationFrame;
use EasyAdminMessengerManager\Message\MessageFinder;
use EasyAdminMessengerManager\Message\MessageView;
use EasyAdminMessengerManager\Payload\PayloadNormalizer;
use EasyAdminMessengerManager\Security\Permission;
use EasyAdminMessengerManager\Transport\TransportException;
use EasyAdminMessengerManager\Transport\TransportRegistry;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

abstract class AbstractMessengerDashboardController extends AbstractController
{
    private const int TIMELINE_LIMIT = 50;

    public static function getSubscribedServices(): array
    {
        return [
            ...parent::getSubscribedServices(),
            TransportRegistry::class,
            MessageFinder::class,
            PayloadNormalizer::class,
            AdminUrlGeneratorInterface::class,
            HistoryRepository::class => '?'.HistoryRepository::class,
        ];
    }

    #[AdminRoute('/', name: 'overview')]
    public function overview(Request $request): Response
    {
        return $this->page('overview', $request, [
            'hidden_transports' => $this->hiddenTransports(),
            'stuck_after_seconds' => $this->stuckAfterSeconds(),
        ]);
    }

    #[AdminRoute('/messages', name: 'messages')]
    public function messages(Request $request): Response
    {
        return $this->page('messages', $request, [
            'per_page' => $this->messagesPerPage(),
            'hidden_transports' => $this->hiddenTransports(),
        ]);
    }

    #[AdminRoute('/failed', name: 'failed')]
    public function failed(Request $request): Response
    {
        return $this->page('failed/index', $request, [
            'per_page' => $this->messagesPerPage(),
            'hidden_transports' => $this->hiddenTransports(),
        ]);
    }

    #[AdminRoute('/messages/{transport}/{messageId}', name: 'message')]
    public function message(Request $request, string $transport, string $messageId): Response
    {
        if (\in_array($transport, $this->hiddenTransports(), true)) {
            throw $this->createNotFoundException(\sprintf('The transport "%s" is hidden.', $transport));
        }

        try {
            $view = $this->service(MessageFinder::class)->find($transport, $messageId);
        } catch (TransportException $exception) {
            throw $this->createNotFoundException($exception->getMessage(), $exception);
        }

        $normalizer = $this->service(PayloadNormalizer::class);

        return $this->page('message', $request, [
            'view' => $view,
            'timeline' => $this->timeline($view),
            'is_failure' => $this->service(TransportRegistry::class)->isFailure($transport),
            'payload' => $normalizer->json($view->envelope->getMessage()),
            'stamps' => $normalizer->json($view->stamps()),
            'application_frame' => ApplicationFrame::of($view->flattenException, (string) $this->getParameter('kernel.project_dir')),
            'return_to' => $this->returnTo($request),
        ]);
    }

    #[AdminRoute('/history', name: 'history')]
    public function history(Request $request): Response
    {
        if (!$this->isHistoryEnabled()) {
            throw $this->createNotFoundException('Messenger history is disabled.');
        }

        return $this->page('history', $request, [
            'per_page' => $this->messagesPerPage(),
        ]);
    }

    #[AdminRoute('/history/{entryId}', name: 'history_entry', options: ['requirements' => ['entryId' => '\d+']])]
    public function historyEntry(Request $request, int $entryId): Response
    {
        $entry = $this->historyRepository()?->find($entryId) ?? throw $this->createNotFoundException('History entry not found.');

        return $this->page('history_entry', $request, [
            'entry' => $entry,
            'payload_is_json' => \is_string($entry['payload']) && json_validate($entry['payload']),
            'return_to' => $this->returnTo($request),
        ]);
    }

    protected function messagesPerPage(): int
    {
        return 50;
    }

    protected function refreshSeconds(): int
    {
        return 10;
    }

    /**
     * @return list<string>
     */
    protected function hiddenTransports(): array
    {
        return [];
    }

    protected function stuckAfterSeconds(): int
    {
        return 900;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    protected function page(string $template, Request $request, array $parameters): Response
    {
        $this->denyAccessUnlessGranted(Permission::View->value);

        return $this->render(\sprintf('@EasyAdminMessengerManager/%s.html.twig', $template), [
            ...$parameters,
            'eamm_controller' => static::class,
            'eamm_dashboard' => (string) $request->attributes->get(EA::DASHBOARD_CONTROLLER_FQCN),
            'eamm_history_enabled' => $this->isHistoryEnabled(),
            'eamm_refresh_seconds' => $this->refreshSeconds(),
        ]);
    }

    private function returnTo(Request $request): ?string
    {
        $base = $this->service(AdminUrlGeneratorInterface::class)->unsetAll()->setController(static::class)->setAction('overview')->generateUrl();

        return ReturnUrl::forBaseUrl($base)->validated($request->query->all()['returnTo'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function timeline(MessageView $view): ?array
    {
        $trackingId = $view->trackingId();
        if (null === $trackingId) {
            return null;
        }

        return $this->historyRepository()?->timeline($trackingId, self::TIMELINE_LIMIT);
    }

    private function isHistoryEnabled(): bool
    {
        return $this->container->has(HistoryRepository::class);
    }

    private function historyRepository(): ?HistoryRepository
    {
        return $this->isHistoryEnabled() ? $this->container->get(HistoryRepository::class) : null;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        return $this->container->get($id);
    }
}
