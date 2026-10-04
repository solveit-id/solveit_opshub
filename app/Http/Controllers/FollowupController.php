<?php

namespace App\Http\Controllers;

use App\Application\PolicyScheduling\IdempotencyService;
use App\Application\RenewalFollowups\FollowupWorkflow;
use App\Application\RenewalFollowups\RenewalAccess;
use App\Application\RenewalFollowups\RenewalScheduler;
use App\Models\ClientFollowup;
use App\Models\IdempotencyKey;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FollowupController extends Controller
{
    public function action(Request $request, Organization $organization, ClientFollowup $followup, string $action)
    {
        $service = $followup->cycle->subscription;
        app(RenewalAccess::class)->require($request->user(), $organization, $service, $action === 'verify_renewal' ? 'renewal.manage' : 'followup.manage');
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'], 'subscription_version' => ['nullable', 'integer', 'min:1'],
            'assignee_user_id' => ['nullable', 'integer'], 'next_followup_at' => ['nullable', 'date'], 'response_summary' => ['nullable', 'string', 'max:2000'],
            'blocker' => ['nullable', 'string', 'max:2000'], 'client_commitment' => ['nullable', 'string', 'max:2000'], 'reason' => ['nullable', 'string', 'max:2000'],
            'template_draft_id' => ['nullable', 'integer'], 'contact_id' => ['nullable', 'integer'], 'sent_at' => ['nullable', 'date'], 'manual_channel' => ['nullable', 'string', 'max:80'],
            'evidence_id' => ['nullable', 'integer'], 'date_precision' => ['nullable', Rule::in(['date', 'instant'])], 'expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'expires_at' => ['nullable', 'date'], 'source_timezone' => ['nullable', 'timezone:all'], 'source' => ['nullable', 'string', 'max:255'], 'renew_by' => ['nullable', 'date'],
            'snoozed_until' => ['nullable', 'date'],
        ]);
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/^[a-zA-Z0-9-]{8,80}$/', $key), 422, 'Idempotency-Key wajib.');
        $result = DB::transaction(function () use ($request, $organization, $followup, $action, $data, $key) {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            // Recheck current membership/scope even for an idempotent replay.
            app(RenewalAccess::class)->require($request->user(), $organization, $followup->cycle->subscription, $action === 'verify_renewal' ? 'renewal.manage' : 'followup.manage');
            $hash = hash('sha256', json_encode([$followup->id, $action, $data], JSON_THROW_ON_ERROR));
            $existing = IdempotencyKey::where('organization_id', $organization->id)->where('actor_user_id', $request->user()->id)->where('action', 'followup.action')->where('key', $key)->first();
            if ($existing) {
                abort_unless($existing->request_hash === $hash, 409);

                return $existing->response;
            }
            if ($action === 'snooze') {
                abort_unless($request->filled('snoozed_until') && $request->filled('reason'), 422);
                $changed = app(RenewalScheduler::class)->snooze($organization, $request->user(), $followup->cycle->subscription, $data['version'], CarbonImmutable::parse($data['snoozed_until']), $data['reason']);
            } else {
                $changed = app(FollowupWorkflow::class)->act($organization, $request->user(), $followup, $action, $data);
            }
            $result = $changed->only(['id', 'state', 'version', 'assignee_user_id', 'next_followup_at']);
            app(IdempotencyService::class)->remember($organization, $request->user(), 'followup.action', $key, $hash, $result, now()->addDay());

            return $result;
        });

        return response()->json(['data' => $result]);
    }
}
