<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\App\Admin;

use EasyAdminMessengerManager\Controller\AbstractMessengerDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;

#[AdminRoute('/messenger', name: 'messenger')]
final class MessengerDashboardController extends AbstractMessengerDashboardController
{
}
