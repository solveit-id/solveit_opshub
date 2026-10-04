<?php

namespace App\Http\Controllers;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\IdentityAccess\ProjectAccess;
use App\Application\TelegramNotifications\NotificationHealth;
use App\Application\TelegramNotifications\TelegramConfiguration;
use App\Models\Organization;
use App\Models\TelegramBinding;
use App\Models\TelegramDelivery;
use App\Models\TelegramDestination;
use App\Models\TelegramIntegrationFinding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class NotificationController extends Controller
{
    public function index(Request $request, Organization $organization)
    {
        $owner = app(ProjectAccess::class)->owner($request->user(), $organization);
        $visible = app(ProjectAccess::class)->query($request->user(), $organization)->pluck('id')->all();
        $deliveries = TelegramDelivery::forOrganization($organization)->latest('id')->limit(1000)->get()->filter(function ($d) use ($owner, $visible, $request) {
            if ($owner) {
                return true;
            }
            if ($d->binding_id && TelegramBinding::find($d->binding_id)?->user_id !== $request->user()->id) {
                return false;
            }
            $destination = $d->telegram_destination_id ? TelegramDestination::find($d->telegram_destination_id) : null;
            if ($destination?->chat_type === 'private' && $destination->member_user_id !== $request->user()->id) {
                return false;
            }
            if ($d->notification_kind === 'binding_notice') {
                return false;
            }
            if ($d->project_ids === [] && ! $d->binding_id) {
                return false;
            }

            return array_diff($d->project_ids, $visible) === [];
        })->map(fn ($d) => [...$d->only(['id', 'outbox_event_id', 'notification_kind', 'state', 'severity', 'attempts', 'result_code', 'available_at', 'sent_at', 'message_id', 'fake', 'uncertain', 'revision']), 'duplicate_possible' => $d->uncertain && $d->attempts > 1]);
        $data = ['organization' => $organization->only(['id', 'name']), 'health' => app(NotificationHealth::class)->snapshot($organization), 'canConfigure' => $owner, 'counts' => $deliveries->countBy('state'), 'deliveries' => $deliveries->take(100)->values(), 'findings' => $owner ? TelegramIntegrationFinding::forOrganization($organization)->latest('last_detected_at')->limit(100)->get() : []];

        return $request->expectsJson() ? response()->json(['data' => $data]) : Inertia::render('Telegram/Deliveries', $data);
    }

    public function acknowledge(Request $request, Organization $organization, TelegramIntegrationFinding $finding)
    {
        app(TelegramConfiguration::class)->owner($organization, $request->user());
        abort_unless($finding->organization_id === $organization->id, 404);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($organization, $request, $finding, $data) {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $finding = TelegramIntegrationFinding::whereKey($finding->id)->lockForUpdate()->firstOrFail();
            abort_unless($finding->version === $data['version'] && $finding->state !== 'resolved', 409);
            $finding->update(['state' => 'acknowledged', 'acknowledged_by_user_id' => $request->user()->id, 'acknowledged_at' => now('UTC'), 'version' => $finding->version + 1]);
            app(AuditWriter::class)->write($organization, 'telegram.finding_acknowledged', 'TelegramIntegrationFinding', $finding->id, 'success', $request->user());
        });

        return response()->json(['data' => ['state' => 'acknowledged']]);
    }
}
