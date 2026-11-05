<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Component;

use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\MessageView;
use EasyAdminMessengerManager\Message\Source\MessageSources;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('EAMM:MessageLocator', template: '@EasyAdminMessengerManager/components/message_locator.html.twig')]
final class MessageLocatorComponent extends AbstractController
{
    use DashboardLinks;
    use DefaultActionTrait;
    use GuardsViewAccess;
    use ReportsLoadFailures;

    #[LiveProp]
    public string $trackingId = '';

    public function __construct(
        private readonly MessageSources $sources,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function getView(): ?MessageView
    {
        if ('' === $this->trackingId) {
            return null;
        }

        return $this->loadSafely(fn (): ?MessageView => $this->sources->failed()->first(new MessageFilter(trackingId: $this->trackingId)), null);
    }
}
