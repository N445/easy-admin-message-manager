<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Batch;

enum BatchAction: string
{
    case Requeue = 'requeue';
    case Remove = 'remove';
}
