<?php

namespace App\Infrastructure\Connectors;

enum Capability: string
{
    case Connection = 'connection';
    case AccountDiskRead = 'account_disk_read';
    case FullBackupTrigger = 'full_backup_trigger';
    case BackupArtifactPull = 'backup_artifact_pull';
    case SftpRead = 'sftp_read';
    case FileBackup = 'file_backup';
    case DatabaseBackup = 'database_backup';
    case FullAccountRestore = 'full_account_restore';
    case ApplicationHealth = 'application_health';
    case DeploymentExecute = 'deployment_execute';
}
