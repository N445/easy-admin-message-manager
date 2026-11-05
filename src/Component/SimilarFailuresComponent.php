<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Component;

use EasyAdminMessengerManager\Message\MessageFilter;
use EasyAdminMessengerManager\Message\Source\MessageSources;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('EAMM:SimilarFailures', template: '@EasyAdminMessengerManager/components/similar_failures.html.twig')]
final class SimilarFailuresComponent extends AbstractController
{
    use DashboardLinks;
    use DefaultActionTrait;
    use GuardsViewAccess;
    use ReportsLoadFailures;

    private const array FIELDS = ['class', 'exception', 'origin'];

    public const int COUNT_LIMIT = 1000;

    #[LiveProp]
    public string $field = '';

    #[LiveProp]
    public string $value = '';

    public function __construct(
        private readonly MessageSources $sources,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function getCount(): ?int
    {
        if (!\in_array($this->field, self::FIELDS, true) || '' === $this->value) {
            return null;
        }

        return $this->loadSafely(fn (): int => $this->sources->failed()->countUpTo(MessageFilter::fromArray([$this->field => $this->value]), self::COUNT_LIMIT + 1), null);
    }
}
