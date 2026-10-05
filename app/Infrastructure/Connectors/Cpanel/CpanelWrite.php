<?php

namespace App\Infrastructure\Connectors\Cpanel;

enum CpanelWrite: string
{
    case FullBackupToHome = 'Backup/fullbackup_to_homedir?homedir=include';
}
