<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Component;

use Symfony\UX\LiveComponent\Attribute\LiveProp;

trait DashboardLinks
{
    #[LiveProp]
    public string $controller = '';

    #[LiveProp]
    public string $dashboard = '';
}
