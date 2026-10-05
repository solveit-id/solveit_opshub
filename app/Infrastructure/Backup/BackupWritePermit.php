<?php

namespace App\Infrastructure\Backup;

use App\Application\Backups\BackupRuns;
use App\Infrastructure\Connectors\ConnectorConfig;
use App\Models\BackupRun;

final readonly class BackupWritePermit
{
    private function __construct(public int $runId, private string $token) {}

    public static function issue(int $runId, string $token): self
    {
        return new self($runId, $token);
    }

    public function allows(ConnectorConfig $config): bool
    {
        $run = BackupRun::find($this->runId);

        return $run && ! $run->fake && $run->organization_id === $config->organizationId && $run->hosting_account_id === $config->hostingAccountId
            && $run->connector_version === $config->version && $run->state === 'awaiting_source' && $run->lease_owner === $this->token
            && $run->leased_until?->isFuture() && app(BackupRuns::class)->eligibility($run, false) === null;
    }
}
