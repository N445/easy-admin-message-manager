<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Component;

trait ReportsLoadFailures
{
    public bool $loadFailed = false;

    /**
     * @template T
     *
     * @param \Closure(): T $load
     * @param T             $fallback
     *
     * @return T
     */
    private function loadSafely(\Closure $load, mixed $fallback): mixed
    {
        try {
            return $load();
        } catch (\Throwable $exception) {
            $this->loadFailed = true;
            $this->logger->error('Messenger dashboard could not load its data: {message}', ['message' => $exception->getMessage(), 'exception' => $exception, 'component' => static::class]);

            return $fallback;
        }
    }
}
