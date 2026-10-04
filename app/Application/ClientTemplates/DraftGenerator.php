<?php

namespace App\Application\ClientTemplates;

use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Application\RenewalFollowups\Expiry;
use App\Application\RenewalFollowups\RenewalAccess;
use App\Models\Asset;
use App\Models\ClientFollowup;
use App\Models\Contact;
use App\Models\Evidence;
use App\Models\HostingAccount;
use App\Models\MessageTemplate;
use App\Models\MessageTemplateVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TemplateDraft;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DraftGenerator
{
    public function generate(Organization $org, ClientFollowup $followup, ?string $key = null, ?User $actor = null, array $context = [], ?int $contactId = null, ?CarbonImmutable $now = null): TemplateDraft
    {
        $now ??= CarbonImmutable::now('UTC');
        abort_unless($followup->organization_id === $org->id, 404);
        if ($actor) {
            app(RenewalAccess::class)->require($actor, $org, $followup->cycle->subscription, 'followup.manage');
        } else {
            abort_unless($context === [] && $contactId === null, 422);
        }
        app(TemplateGovernance::class)->seed($org);

        return DB::transaction(function () use ($org, $followup, $key, $actor, $context, $contactId, $now) {
            $followup = ClientFollowup::forOrganization($org)->whereKey($followup->id)->lockForUpdate()->firstOrFail();
            $cycle = $followup->cycle;
            $service = $cycle->subscription;
            if ($actor) {
                app(RenewalAccess::class)->require($actor, $org, $service, 'followup.manage');
            }
            $projects = Project::forOrganization($org)->whereIn('id', app(RenewalAccess::class)->projectIds($service))->orderBy('id')->get();
            $clientIds = $projects->pluck('client_id')->filter()->unique();
            $contacts = Contact::forOrganization($org)->whereIn('client_id', $clientIds)->whereNotNull('contact_value')->orderBy('id')->get();
            if ($contactId !== null) {
                abort_unless($contacts->contains('id', $contactId), 422, 'Contact harus terkait impacted client.');
            }
            $contact = $contacts->firstWhere('id', $contactId ?? $followup->contact_id);
            if (! $contact && $clientIds->count() === 1) {
                $contact = $contacts->first();
            }
            if ($context !== [] || $contactId !== null) {
                abort_unless(array_diff(array_keys($context), ['requested_action', 'impact_summary', 'approval_summary', 'safe_access_instruction', 'review_evidence_id']) === [], 422);
                foreach ($context as $name => $value) {
                    if ($name !== 'review_evidence_id') {
                        app(PlaintextRenderer::class)->safe((string) $value);
                    }
                }
                $merged = [...($followup->template_context ?? []), ...$context];
                if ($merged !== ($followup->template_context ?? []) || $contact?->id !== $followup->contact_id) {
                    $followup->update(['template_context' => $merged, 'contact_id' => $contact?->id, 'version' => $followup->version + 1]);
                }
            }
            $expiry = app(Expiry::class)->instant($cycle->expiry_snapshot);
            $verified = Evidence::forOrganization($org)->whereKey($cycle->expiry_snapshot['evidence_id'] ?? null)->where('kind', 'provider_expiry')->whereNotNull('verified_at')->whereNotNull('verified_by_user_id')->exists();
            $key ??= (! $expiry || ! $verified) ? 'TPL-04' : ($expiry->lessThan($now) ? 'TPL-05' : ($service->service_kind === 'domain' ? 'TPL-02' : 'TPL-01'));
            $template = MessageTemplate::forOrganization($org)->where('template_key', $key)->firstOrFail();
            $version = MessageTemplateVersion::forOrganization($org)->where('message_template_id', $template->id)->where('version', $template->published_version)->firstOrFail();
            $zone = $cycle->expiry_snapshot['source_timezone'] ?? $org->timezone;
            $vars = [
                'contact_salutation' => $contact?->name ?: 'Bapak/Ibu', 'client_name' => $contact?->client?->name,
                'project_name' => $projects->filter(fn ($project) => $project->client_id === $contact?->client_id)->pluck('name')->implode(', '),
                'service_name' => $service->service_name, 'domain_name' => $service->service_kind === 'domain' ? Asset::forOrganization($org)->whereKey($service->resource_asset_id)->where('kind', 'domain')->value('canonical_identity') : null,
                'provider_name' => $service->resource_asset_id ? HostingAccount::forOrganization($org)->where('asset_id', $service->resource_asset_id)->value('provider') : null,
                'expiry_display' => $expiry && $verified ? ($cycle->expiry_snapshot['date_precision'] === 'date' ? $expiry->setTimezone($zone)->format('d-m-Y').' (jam tidak diketahui)' : $expiry->setTimezone($zone)->format('d-m-Y H:i').' '.$zone) : null,
                'action_deadline_display' => $service->renew_by?->setTimezone($org->timezone)->format('d-m-Y H:i').' '.$org->timezone,
                'requested_action' => null, 'impact_summary' => null, 'approval_summary' => null, 'safe_access_instruction' => null,
            ];
            // A null deadline must remain absent, including its optional sentence.
            if ($service->renew_by === null) {
                $vars['action_deadline_display'] = null;
            }
            $review = $followup->template_context ?? [];
            foreach (['requested_action', 'impact_summary', 'approval_summary', 'safe_access_instruction'] as $name) {
                $vars[$name] = $review[$name] ?? null;
            }
            $reasons = [];
            if (! $contact || trim($contact->contact_value ?? '') === '') {
                $reasons[] = 'contact_target';
            }
            if (! $projects->contains(fn ($p) => $p->internal_pic_user_id && app(OrganizationAuthorizationService::class)->can(User::findOrFail($p->internal_pic_user_id), $org, 'followup.manage'))) {
                $reasons[] = 'active_internal_pic';
            }
            if ($key === 'TPL-03' && (! $followup->attempts()->exists() || ! $followup->overdue($now))) {
                $reasons[] = 'recorded_contact_overdue_required';
            }
            if ($key === 'TPL-05' && (! $expiry || ! $verified || ! $expiry->lessThan($now))) {
                $reasons[] = 'verified_elapsed_expiry_required';
            }
            if (in_array($key, ['TPL-01', 'TPL-02'], true) && (! $expiry || ! $verified || $expiry->lessThan($now))) {
                $reasons[] = 'verified_future_expiry_required';
            }
            if ($key === 'TPL-10' && ($cycle->state !== 'verified' || ! $cycle->verified_at || ! $cycle->evidence_id)) {
                $reasons[] = 'verified_renewal_required';
            }
            $reviewKinds = ['TPL-06' => 'approved_secure_access', 'TPL-07' => 'capacity_review', 'TPL-08' => 'change_plan', 'TPL-09' => 'incident'];
            if (isset($reviewKinds[$key]) && ! Evidence::forOrganization($org)->whereKey($review['review_evidence_id'] ?? null)->where('kind', $reviewKinds[$key])->whereNotNull('verified_at')->whereNotNull('verified_by_user_id')->exists()) {
                $reasons[] = 'reviewed_evidence_required';
            }
            if ($key === 'TPL-10' && $cycle->state === 'verified') {
                // Closing template reports verified new expiry, not the old cycle snapshot.
                $freshExpiry = app(Expiry::class)->instant(app(Expiry::class)->snapshot($service));
                $vars['expiry_display'] = $freshExpiry?->setTimezone($service->source_timezone ?? $org->timezone)->format('d-m-Y');
                if ($service->date_precision === 'date') {
                    $vars['expiry_display'] .= ' (jam tidak diketahui)';
                }
            }
            foreach ($vars as $name => $value) {
                try {
                    $vars[$name] = $value === null ? null : app(PlaintextRenderer::class)->safe((string) $value);
                } catch (ValidationException) {
                    $vars[$name] = null;
                    $reasons[] = 'unsafe_source_'.$name;
                }
            }
            $rendered = app(PlaintextRenderer::class)->render($version->body, $vars, $version->mandatory_variables);
            $reasons = array_values(array_unique([...$rendered['missing'], ...$reasons]));
            $hash = hash('sha256', json_encode([$service->version, $cycle->version, $followup->template_context, $contact?->getAttributes(), $projects->map->only(['id', 'name', 'client_id', 'internal_pic_user_id', 'version'])->all(), $rendered['variables'], $reasons], JSON_THROW_ON_ERROR));
            TemplateDraft::forOrganization($org)->where('client_followup_id', $followup->id)->where('template_key', $key)->where('source_hash', '!=', $hash)->whereIn('draft_status', ['ready', 'blocked_missing_data'])->update(['draft_status' => 'stale']);
            $draft = TemplateDraft::firstOrCreate(['client_followup_id' => $followup->id, 'message_template_version_id' => $version->id, 'source_hash' => $hash], [
                'organization_id' => $org->id, 'template_key' => $key, 'template_version' => $version->version, 'source_entity_version' => $service->version,
                'contact_id' => $contact?->id, 'variables_snapshot' => $rendered['variables'], 'rendered_body' => $rendered['body'], 'draft_status' => $reasons === [] ? 'ready' : 'blocked_missing_data',
                'blocked_reasons' => $reasons, 'generated_at' => $now, 'last_edited_by' => $context !== [] ? $actor?->id : null,
            ]);

            return $draft->fresh();
        });
    }
}
