<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Security;

enum Permission: string
{
    case View = 'EAMM_VIEW';
    case Retry = 'EAMM_RETRY';
    case Remove = 'EAMM_REMOVE';

    public function configKey(): string
    {
        return strtolower($this->name);
    }
}
