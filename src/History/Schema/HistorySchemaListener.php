<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\History\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

final readonly class HistorySchemaListener
{
    public function __construct(private Connection $historyConnection)
    {
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        if ($event->getEntityManager()->getConnection() !== $this->historyConnection || !$this->passesAssetFilter()) {
            return;
        }

        $event->setSchema(HistoryTable::addTo($event->getSchema()));
    }

    private function passesAssetFilter(): bool
    {
        $filter = $this->historyConnection->getConfiguration()->getSchemaAssetsFilter();

        return null === $filter || $filter(HistoryTable::NAME);
    }
}
