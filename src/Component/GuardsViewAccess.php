<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Component;

use EasyAdminMessengerManager\Security\Permission;
use Symfony\UX\LiveComponent\Attribute\PreReRender;

trait GuardsViewAccess
{
    public function mount(): void
    {
        $this->denyAccessUnlessGranted(Permission::View->value);
    }

    #[PreReRender]
    public function guardViewAccess(): void
    {
        $this->denyAccessUnlessGranted(Permission::View->value);
    }
}
