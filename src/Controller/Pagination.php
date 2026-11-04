<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Controller;

final readonly class Pagination
{
    private function __construct(
        public int $total,
        public int $page,
        public int $perPage,
        public int $pages,
    ) {
    }

    public static function of(int $total, int $requestedPage, int $perPage): self
    {
        $perPage = max(1, $perPage);
        $pages = max(1, (int) ceil($total / $perPage));

        return new self($total, min(max(1, $requestedPage), $pages), $perPage, $pages);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
