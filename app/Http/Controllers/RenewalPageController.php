<?php

namespace App\Http\Controllers;

use App\Application\ClientTemplates\DraftGenerator;
use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Application\IdentityAccess\ProjectAccess;
use App\Application\RenewalFollowups\RenewalAccess;
use App\Application\RenewalFollowups\RenewalScheduler;
use App\Application\RenewalFollowups\SubscriptionRegistry;
use App\Models\ClientFollowup;
use App\Models\Contact;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\ServiceSubscription;
use App\Models\TemplateDraft;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class RenewalPageController extends Controller
{
    public function index(Request $request, Organization $organization)
    {
        $visible = app(ProjectAccess::class)->query($request->user(), $organization)->pluck('id')->all();
        $owner = app(ProjectAccess::class)->owner($request->user(), $organization);
        $items = ServiceSubscription::forOrganization($organization)->orderBy('id')->get()->filter(fn ($s) => $owner || array_intersect($visible, app(RenewalAccess::class)->projectIds($s)->all()) !== [])->map(function ($s) use ($request, $organization) {
            $cycle = $s->cycles()->whereNotNull('active_subscription_id')->first();
            $f = $cycle?->followup;

            return [...$s->only(['id', 'service_name', 'service_kind', 'version', 'action_owner', 'date_precision', 'expiry_date', 'expires_at', 'source_timezone', 'renew_by', 'payment_status']),
                'followup' => $f ? [...$f->only(['id', 'state', 'severity', 'next_followup_at', 'snoozed_until']), 'overdue' => $f->overdue()] : null,
                'can_manage' => $this->canManage($request, $organization, $s),
                'impacted_projects' => app(ProjectAccess::class)->query($request->user(), $organization)->whereIn('id', app(RenewalAccess::class)->projectIds($s))->get(['id', 'name']),
                'history' => $s->cycles()->whereNull('active_subscription_id')->with('followup')->get()->map(fn ($c) => ['sequence' => $c->sequence, 'state' => $c->state, 'followup_id' => $c->followup?->id]),
            ];
        })->values();
        $data = ['organization' => $organization->only(['id', 'name', 'timezone']), 'items' => $items, 'isOwner' => $owner];

        return $request->is('api/*') ? response()->json(['data' => $data]) : Inertia::render('Renewals/Index', $data);
    }

    public function show(Request $request, Organization $organization, ClientFollowup $followup)
    {
        $s = $followup->cycle->subscription;
        app(RenewalAccess::class)->require($request->user(), $organization, $s);
        $ids = app(RenewalAccess::class)->projectIds($s);
        $visible = app(ProjectAccess::class)->query($request->user(), $organization)->whereIn('id', $ids)->get(['id', 'name', 'client_id']);
        $all = app(ProjectAccess::class)->owner($request->user(), $organization) || $visible->count() === count($ids);
        $manageable = $all && $this->canManage($request, $organization, $s);
        $data = ['organization' => $organization->only(['id', 'name', 'timezone']), 'service' => $s->only(['id', 'service_name', 'version', 'date_precision', 'expiry_date', 'expires_at', 'source_timezone', 'payment_status', 'action_owner', 'renew_by']),
            'followup' => [...$followup->only(['id', 'state', 'severity', 'version', 'next_followup_at', 'snoozed_until', 'assignee_user_id', 'purpose']), 'overdue' => $followup->overdue(), 'cycle_state' => $followup->cycle->state],
            'impactedProjects' => $visible->map->only(['id', 'name']), 'fullScope' => $all, 'canManage' => $manageable,
            'contacts' => $all ? Contact::forOrganization($organization)->whereIn('client_id', $visible->pluck('client_id'))->get(['id', 'name', 'preferred_manual_channel']) : [],
            'draft' => $all ? TemplateDraft::forOrganization($organization)->where('client_followup_id', $followup->id)->orderByDesc('id')->first()?->only(['id', 'template_key', 'template_version', 'contact_id', 'draft_status', 'blocked_reasons', 'rendered_body', 'generated_at']) : null,
            'attempts' => $all ? $followup->attempts()->orderByDesc('id')->get(['id', 'actor_user_id', 'contact_id', 'sent_at', 'manual_channel', 'draft_version', 'sent_body', 'evidence_id']) : [],
            'assignees' => $manageable ? Membership::where('organization_id', $organization->id)->where('is_active', true)->with('user')->get()->filter(fn ($m) => $m->user && $m->user->is_active && $this->memberCanManage($m->user, $organization, $s))->map(fn ($m) => $m->user->only(['id', 'name']))->values() : [],
        ];
        if ($all) {
            $data['followup'] = [...$data['followup'], ...$followup->only(['response_summary', 'blocker', 'client_commitment', 'resolution_reason', 'template_context'])];
        }

        return $request->is('api/*') ? response()->json(['data' => $data]) : Inertia::render('Renewals/Followup', $data);
    }

    public function draft(Request $request, Organization $organization, ClientFollowup $followup)
    {
        app(RenewalAccess::class)->require($request->user(), $organization, $followup->cycle->subscription, 'followup.manage');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'template_key' => ['nullable', 'regex:/^TPL-(0[1-9]|10)$/'], 'contact_id' => ['nullable', 'integer'], 'context' => ['sometimes', 'array'], 'context.*' => ['nullable', 'string', 'max:2000']]);
        $draft = DB::transaction(function () use ($request, $organization, $followup, $data) {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $locked = ClientFollowup::whereKey($followup->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $data['version'], 409);

            return app(DraftGenerator::class)->generate($organization, $locked, $data['template_key'] ?? null, $request->user(), $data['context'] ?? [], $data['contact_id'] ?? null);
        });

        return response()->json(['data' => $draft->only(['id', 'template_key', 'template_version', 'contact_id', 'draft_status', 'blocked_reasons', 'rendered_body', 'generated_at']), 'followup_version' => $followup->fresh()->version]);
    }

    public function start(Request $request, Organization $organization, ServiceSubscription $serviceSubscription)
    {
        app(RenewalAccess::class)->require($request->user(), $organization, $serviceSubscription, 'followup.manage');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $f = DB::transaction(function () use ($request, $organization, $serviceSubscription, $data) {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $service = ServiceSubscription::whereKey($serviceSubscription->id)->lockForUpdate()->firstOrFail();
            abort_unless($service->version === $data['version'], 409);
            $cycle = app(SubscriptionRegistry::class)->cycle($service);
            $f = app(RenewalScheduler::class)->followup($cycle, $service->date_precision === 'unknown' ? 'verify_expiry' : 'renewal');
            app(DraftGenerator::class)->generate($organization, $f, actor: $request->user());

            return $f;
        });

        return response()->json(['data' => ['id' => $f->id]], 201);
    }

    private function canManage(Request $r, Organization $org, ServiceSubscription $s): bool
    {
        return $this->memberCanManage($r->user(), $org, $s);
    }

    private function memberCanManage($user, Organization $org, ServiceSubscription $s): bool
    {
        $ids = app(RenewalAccess::class)->projectIds($s);

        return app(OrganizationAuthorizationService::class)->can($user, $org, 'followup.manage') && (app(ProjectAccess::class)->owner($user, $org) || ($ids->isNotEmpty() && app(ProjectAccess::class)->query($user, $org)->whereIn('id', $ids)->count() === count($ids)));
    }
}
