<?php

namespace App\Http\Controllers;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\Backups\BackupArtifactAccess;
use App\Application\Backups\BackupRetention;
use App\Application\Backups\TemporarySourceCleanup;
use App\Infrastructure\Backup\PrivateObjectStore;
use App\Infrastructure\Connectors\ConnectorFailure;
use App\Models\BackupArtifact;
use App\Models\BackupPolicy;
use App\Models\BackupRetentionReport;
use App\Models\Organization;
use Illuminate\Http\Request;

class BackupArtifactController extends Controller
{
    public function issue(Request $request, Organization $organization, BackupArtifact $artifact)
    {
        return response()->json(['data' => app(BackupArtifactAccess::class)->issue($organization, $request->user(), $artifact)], 201);
    }

    public function download(Request $request, Organization $organization, string $token)
    {
        $access = app(BackupArtifactAccess::class);
        try {
            $artifact = $access->authorizeLink($organization, $request->user(), $token);
        } catch (ConnectorFailure) {
            abort(409, 'Private artifact is unavailable; review storage evidence.');
        }
        $actor = $request->user();
        app(AuditWriter::class)->write($organization, 'backup.download.started', 'backup_artifact', $artifact->id, 'success', $actor);

        return response()->streamDownload(function () use ($access, $organization, $actor, $artifact, $token): void {
            $stream = null;
            $bytes = 0;
            $state = 'failed';
            $hash = hash_init('sha256');
            $nextCheck = 0;
            try {
                $stream = app(PrivateObjectStore::class)->read($artifact->object_reference, $artifact->object_version);
                while (! feof($stream)) {
                    if (hrtime(true) >= $nextCheck) {
                        $access->authorizeLink($organization, $actor->fresh(), $token, false);
                        $nextCheck = hrtime(true) + 1_000_000_000;
                    }
                    $chunk = fread($stream, 65536);
                    if ($chunk === false) {
                        throw new \RuntimeException('Private read failed.');
                    }
                    $bytes += strlen($chunk);
                    if ($bytes > $artifact->encrypted_bytes) {
                        throw new \RuntimeException('Private read limit exceeded.');
                    }
                    hash_update($hash, $chunk);
                    echo $chunk;
                }
                $access->authorizeLink($organization, $actor->fresh(), $token, false);
                $state = $bytes === $artifact->encrypted_bytes && hash_final($hash) === $artifact->encrypted_sha256 ? 'completed' : 'failed';
            } catch (\Throwable) {
                $state = 'failed';
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                app(AuditWriter::class)->write($organization, 'backup.download.finished', 'backup_artifact', $artifact->id, $state, $actor, after: ['bytes' => $bytes]);
            }
        }, 'opshub-backup-'.$artifact->artifact_reference.'.opshub.enc', ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function hold(Request $request, Organization $organization, BackupArtifact $artifact)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'legal_hold' => ['required', 'boolean']]);

        return response()->json(['data' => app(BackupArtifactAccess::class)->hold($organization, $request->user(), $artifact, $data['version'], $data['legal_hold'])]);
    }

    public function approveRetention(Request $request, Organization $organization, BackupPolicy $policy)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'retention' => ['required', 'array'], 'cleanup_temporary_source' => ['required', 'boolean']]);

        return response()->json(['data' => app(BackupRetention::class)->approve($organization, $request->user(), $policy, $data['version'], $data['retention'], $data['cleanup_temporary_source'])]);
    }

    public function preview(Request $request, Organization $organization, BackupPolicy $policy)
    {
        return response()->json(['data' => app(BackupRetention::class)->dryRun($organization, $request->user(), $policy)], 201);
    }

    public function apply(Request $request, Organization $organization, BackupRetentionReport $report)
    {
        app(BackupRetention::class)->enqueue($organization, $request->user(), $report);

        return response()->json(['data' => ['state' => 'queued']], 202);
    }

    public function reconcile(Request $request, Organization $organization, BackupArtifact $artifact)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => app(BackupRetention::class)->reconcile($organization, $request->user(), $artifact, $data['version'])]);
    }

    public function cleanupSource(Request $request, Organization $organization, BackupArtifact $artifact)
    {
        return response()->json(['data' => ['state' => app(TemporarySourceCleanup::class)->execute($organization, $request->user(), $artifact)]]);
    }
}
