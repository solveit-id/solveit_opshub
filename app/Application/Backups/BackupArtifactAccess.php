<?php

namespace App\Application\Backups;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Application\IdentityAccess\ProjectAccess;
use App\Infrastructure\Backup\PrivateObjectStore;
use App\Models\BackupArtifact;
use App\Models\BackupRun;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BackupArtifactAccess
{
    public function require(Organization $org, User $actor, BackupArtifact $artifact, bool $download = false): BackupRun
    {
        abort_unless($artifact->organization_id === $org->id, 404);
        $run = BackupRun::findOrFail($artifact->backup_run_id);
        abort_unless($artifact->hosting_account_id === $run->hosting_account_id, 404);
        app(BackupRuns::class)->requireRead($org, $actor, $run);
        if ($download) {
            app(OrganizationAuthorizationService::class)->require($actor, $org, 'backup.download');
        }

        return $run;
    }

    public function protection(BackupRun $run): bool
    {
        $store = app(PrivateObjectStore::class);
        if ($run->fake !== $store->fake() || ($run->fake ? ! app()->environment('testing') : ! config('opshub.live_connectors_enabled'))) {
            return false;
        }
        $protection = $store->protection($run);

        return ($protection['configured'] ?? false) === true && ($protection['private'] ?? false) === true && ($protection['independent'] ?? false) === true && ($protection['transport_protected'] ?? false) === true;
    }

    public function issue(Organization $org, User $actor, BackupArtifact $artifact): array
    {
        return app(BackupExecution::class)->locked($artifact->backup_run_id, function () use ($org, $actor, $artifact): array {
            $artifact = $artifact->fresh();
            $run = $this->require($org->fresh(), $actor->fresh(), $artifact, true);
            abort_unless($artifact->state === 'verified' && $this->protection($run), 409, 'Private artifact backend is unavailable.');
            $token = bin2hex(random_bytes(32));
            $expires = now('UTC')->addMinutes(5);
            $id = DB::table('backup_download_links')->insertGetId(['backup_artifact_id' => $artifact->id, 'user_id' => $actor->id,
                'token_digest' => hash('sha256', $token), 'expires_at' => $expires, 'created_at' => now('UTC')]);
            app(AuditWriter::class)->write($org, 'backup.download_link.issued', 'backup_artifact', $artifact->id, 'success', $actor, after: ['link_id' => $id, 'ttl_seconds' => 300]);

            return ['url' => route('backups.download', [$org->id, $token]), 'expires_at' => $expires->toIso8601String(), 'encrypted' => true];
        });
    }

    public function authorizeLink(Organization $org, User $actor, string $token, bool $metadataCheck = true): BackupArtifact
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 404);
        $link = DB::table('backup_download_links')->where('token_digest', hash('sha256', $token))->first();
        abort_unless($link && $link->user_id === $actor->id && $link->expires_at > now('UTC')->format('Y-m-d H:i:s.u'), 404);
        $artifact = BackupArtifact::findOrFail($link->backup_artifact_id);
        $run = $this->require($org, $actor->fresh(), $artifact, true);
        abort_unless($artifact->state === 'verified' && $this->protection($run), 409);
        if (! $metadataCheck) {
            return $artifact;
        }
        $metadata = app(PrivateObjectStore::class)->metadata($artifact->object_reference, $artifact->object_version);
        abort_unless(($metadata['version'] ?? null) === $artifact->object_version && ($metadata['fake'] ?? null) === $artifact->fake
            && ($metadata['bytes'] ?? null) === $artifact->encrypted_bytes && ($metadata['sha256'] ?? null) === $artifact->encrypted_sha256
            && ($metadata['private'] ?? false) === true && ($metadata['independent'] ?? false) === true && ($metadata['transport_protected'] ?? false) === true, 409);

        return $artifact;
    }

    public function hold(Organization $org, User $actor, BackupArtifact $artifact, int $version, bool $hold): BackupArtifact
    {
        return app(BackupExecution::class)->locked($artifact->backup_run_id, function () use ($org, $actor, $artifact, $version, $hold): BackupArtifact {
            abort_unless(app(ProjectAccess::class)->owner($actor->fresh(), $org->fresh()), 403);
            $artifact = $artifact->fresh();
            $this->require($org, $actor, $artifact);
            abort_unless($artifact->version === $version && ! in_array($artifact->state, ['deleting', 'delete_unknown', 'deleted'], true), 409);
            $artifact->update(['legal_hold' => $hold, 'version' => $version + 1]);
            app(AuditWriter::class)->write($org, 'backup.legal_hold.changed', 'backup_artifact', $artifact->id, 'success', $actor, after: ['legal_hold' => $hold, 'version' => $version + 1]);

            return $artifact;
        });
    }
}
