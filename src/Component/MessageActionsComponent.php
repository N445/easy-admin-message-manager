<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Component;

use EasyAdminMessengerManager\Failed\ActionResult;
use EasyAdminMessengerManager\Failed\FailedMessageRemover;
use EasyAdminMessengerManager\Failed\FailedMessageRetrier;
use EasyAdminMessengerManager\Message\MessageFinder;
use EasyAdminMessengerManager\Message\MessageView;
use EasyAdminMessengerManager\Security\Permission;
use EasyAdminMessengerManager\Transport\TransportException;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('EAMM:MessageActions', template: '@EasyAdminMessengerManager/components/message_actions.html.twig')]
final class MessageActionsComponent extends AbstractController
{
    use DashboardLinks;
    use DefaultActionTrait;
    use GuardsViewAccess;

    #[LiveProp]
    public string $transport = '';

    #[LiveProp]
    public string $messageId = '';

    #[LiveProp]
    public bool $decodable = true;

    #[LiveProp]
    public string $returnTo = '';

    public ?Notice $notice = null;

    public function __construct(
        private readonly FailedMessageRetrier $retrier,
        private readonly FailedMessageRemover $remover,
        private readonly AdminUrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly MessageFinder $finder,
    ) {
    }

    #[LiveAction]
    public function retry(): ?Response
    {
        $this->denyAccessUnlessGranted(Permission::Retry->value, $this->getView());
        $result = $this->attempt(fn (): ActionResult => $this->retrier->retry($this->transport, $this->messageId));

        return $this->conclude($result, 'eamm.flash.retried');
    }

    #[LiveAction]
    public function remove(): ?Response
    {
        $this->denyAccessUnlessGranted(Permission::Remove->value, $this->getView());
        $result = $this->attempt(function (): ActionResult {
            $this->remover->remove($this->transport, $this->messageId);

            return ActionResult::success();
        });

        return $this->conclude($result, 'eamm.flash.removed');
    }

    public function getView(): ?MessageView
    {
        try {
            return $this->finder->find($this->transport, $this->messageId);
        } catch (TransportException) {
            return null;
        }
    }

    public function getListUrl(): string
    {
        if ('' !== $this->returnTo) {
            return $this->returnTo;
        }

        return $this->urls->unsetAll()->setDashboard($this->dashboard)->setController($this->controller)->setAction('failed')->generateUrl();
    }

    /**
     * @param \Closure(): ActionResult $action
     */
    private function attempt(\Closure $action): ActionResult
    {
        try {
            return $action();
        } catch (TransportException $exception) {
            return ActionResult::failure($exception);
        }
    }

    private function conclude(ActionResult $result, string $successMessage): ?Response
    {
        if (!$result->succeeded) {
            $this->notice = Notice::error((string) $result->error);

            return null;
        }

        $this->addFlash('success', $this->translator->trans($successMessage, [], 'EasyAdminMessengerManager'));

        return $this->redirect($this->getListUrl());
    }
}
