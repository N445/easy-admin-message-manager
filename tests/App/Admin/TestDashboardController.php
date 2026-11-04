<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\App\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class TestDashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        return new Response('dashboard');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkTo(MessengerDashboardController::class, 'Messenger', 'fa fa-envelope')->setAction('overview');
    }
}
