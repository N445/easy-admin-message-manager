<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\History;

enum HistoryStatus: string
{
    case Handled = 'handled';
    case Retrying = 'retrying';
    case Failed = 'failed';
}
