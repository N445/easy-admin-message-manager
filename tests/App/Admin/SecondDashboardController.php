<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\App\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/backoffice', routeName: 'backoffice')]
final class SecondDashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        return new Response('second dashboard');
    }
}
