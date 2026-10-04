<?php

namespace App\Infrastructure\Connectors\Cpanel;

enum CpanelRead: string
{
    case Features = 'Features/list_features';
    case Quota = 'Quota/get_quota_info';
}
