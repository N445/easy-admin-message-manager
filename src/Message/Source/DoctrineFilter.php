<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Message\Source;

use Doctrine\DBAL\Query\QueryBuilder;
use EasyAdminMessengerManager\Message\MessageFilter;

final readonly class DoctrineFilter
{
    public function __construct(private MessageFilter $filter)
    {
    }

    public function apply(QueryBuilder $query): QueryBuilder
    {
        $this->contains($query, 'eamm_class', $this->filter->messageClass);
        $this->contains($query, 'eamm_exception', $this->filter->exception);
        $this->origin($query);
        $this->period($query);

        return $query;
    }

    private function contains(QueryBuilder $query, string $name, ?string $term): void
    {
        if (null === $term) {
            return;
        }

        $this->matchesEncoded($query, $name, addslashes($term), substr((string) json_encode($term, \JSON_UNESCAPED_UNICODE), 1, -1));
    }

    private function origin(QueryBuilder $query): void
    {
        $origin = $this->filter->originalTransport;
        if (null === $origin) {
            return;
        }

        $serialized = addslashes(\sprintf('originalReceiverName";s:%d:"%s"', \strlen($origin), $origin));
        $this->matchesEncoded($query, 'eamm_origin', $serialized, \sprintf('"originalReceiverName":"%s"', $origin));
    }

    private function period(QueryBuilder $query): void
    {
        if (null !== $this->filter->from) {
            $query->andWhere('created_at >= :eamm_from')->setParameter('eamm_from', $this->filter->from->format('Y-m-d H:i:s'));
        }

        if (null !== $this->filter->to) {
            $query->andWhere('created_at <= :eamm_to')->setParameter('eamm_to', $this->filter->to->format('Y-m-d H:i:s'));
        }
    }

    private function matchesEncoded(QueryBuilder $query, string $name, string $inBody, string $inHeaders): void
    {
        $query->andWhere(\sprintf("(body LIKE :%1\$s_body ESCAPE '!' OR headers LIKE :%1\$s_headers ESCAPE '!')", $name))
            ->setParameter($name.'_body', '%'.self::escapeLike($inBody).'%')
            ->setParameter($name.'_headers', '%'.self::escapeLike($inHeaders).'%');
    }

    private static function escapeLike(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }
}
