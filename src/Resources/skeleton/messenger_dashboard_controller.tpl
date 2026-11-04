<?php

namespace {{ namespace }};

use EasyAdminMessengerManager\Controller\AbstractMessengerDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;

#[AdminRoute('/{{ route_path }}', name: '{{ route_name }}')]
final class {{ class_name }} extends AbstractMessengerDashboardController
{
}
