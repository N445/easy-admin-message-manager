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

    /**
     * @return list<int|null> page numbers, null where pages are skipped
     */
    public function window(int $radius = 2): array
    {
        $pages = array_unique([1, ...range(max(1, $this->page - $radius), min($this->pages, $this->page + $radius)), $this->pages]);
        sort($pages);

        return self::withGaps($pages);
    }

    /**
     * @param list<int> $pages
     *
     * @return list<int|null>
     */
    private static function withGaps(array $pages): array
    {
        $window = [];
        $previous = 0;
        foreach ($pages as $page) {
            array_push($window, ...self::gap($previous, $page));
            $window[] = $page;
            $previous = $page;
        }

        return $window;
    }

    /**
     * @return list<int|null>
     */
    private static function gap(int $previous, int $page): array
    {
        return match (true) {
            $page - $previous === 2 => [$page - 1],
            $page - $previous > 2 => [null],
            default => [],
        };
    }
}
