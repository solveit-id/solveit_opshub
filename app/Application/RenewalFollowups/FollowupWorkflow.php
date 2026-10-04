<?php

namespace App\Application\RenewalFollowups;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\ClientTemplates\DraftGenerator;
use App\Application\ClientTemplates\PlaintextRenderer;
use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Application\TelegramNotifications\OutboxWriter;
use App\Models\ClientFollowup;
use App\Models\ContactAttempt;
use App\Models\Evidence;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\RenewalReminder;
use App\Models\ServiceSubscription;
use App\Models\TemplateDraft;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FollowupWorkflow
{
    public const ACTIONS = ['update', 'assign', 'claim', 'acknowledge', 'contact', 'waiting_client', 'client_confirmed', 'in_progress', 'cancel', 'reopen', 'verify_renewal'];

    public function act(Organization $org, User $actor, ClientFollowup $followup, string $action, array $data, ?CarbonImmutable $now = null): ClientFollowup
    {
        $now ??= CarbonImmutable::now('UTC');
        abort_unless(in_array($action, self::ACTIONS, true), 422);
        $service = $followup->cycle->subscription;
        app(RenewalAccess::class)->require($actor, $org, $service, $action === 'verify_renewal' ? 'renewal.manage' : 'followup.manage');

        return DB::transaction(function () use ($org, $actor, $followup, $service, $action, $data, $now) {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $service = ServiceSubscription::forOrganization($org)->whereKey($service->id)->lockForUpdate()->firstOrFail();
            $followup = ClientFollowup::forOrganization($org)->whereKey($followup->id)->lockForUpdate()->firstOrFail();
            $cycle = $followup->cycle()->lockForUpdate()->firstOrFail();
            app(RenewalAccess::class)->require($actor, $org, $service, $action === 'verify_renewal' ? 'renewal.manage' : 'followup.manage');
            abort_unless($followup->version === (int) ($data['version'] ?? 0), 409, 'Follow-up berubah; muat ulang.');
            abort_unless($cycle->state === 'active' && $cycle->active_subscription_id === $service->id, 409, 'Cycle tidak aktif.');
            if (in_array($followup->state, ['resolved', 'cancelled'], true)) {
                abort_unless($action === 'reopen', 422, 'Follow-up sudah ditutup.');
            } else {
                abort_unless($action !== 'reopen', 422, 'Hanya follow-up ditutup dapat dibuka kembali.');
            }
            $before = $followup->only(['state', 'version', 'assignee_user_id', 'next_followup_at']);
            $changes = [];
            foreach (['response_summary', 'blocker', 'client_commitment'] as $field) {
                if (array_key_exists($field, $data)) {
                    $changes[$field] = $data[$field] === null ? null : app(PlaintextRenderer::class)->safe($data[$field]);
                }
            }
            if (array_key_exists('next_followup_at', $data)) {
                $changes['next_followup_at'] = $this->instant($data['next_followup_at']);
            }
            if (in_array($action, ['assign', 'claim'], true)) {
                $id = $action === 'claim' ? $actor->id : ($data['assignee_user_id'] ?? null);
                if ($id !== null) {
                    $assignee = User::findOrFail($id);
                    app(RenewalAccess::class)->require($assignee, $org, $service, 'followup.manage');
                }
                $changes['assignee_user_id'] = $id;
            }
            if ($action === 'acknowledge') {
                $changes['acknowledged_at'] = $followup->acknowledged_at ?? $now;
            }
            if ($action === 'contact') {
                $draft = TemplateDraft::forOrganization($org)->where('client_followup_id', $followup->id)->findOrFail($data['template_draft_id'] ?? null);
                $current = app(DraftGenerator::class)->generate($org, $followup, $draft->template_key, $actor, now: $now);
                abort_unless($current->id === $draft->id && $current->draft_status === 'ready' && $current->contact_id === (int) ($data['contact_id'] ?? 0), 409, 'Draft/contact berubah atau blocked; review current draft.');
                $sentAt = $this->instant($data['sent_at'] ?? null);
                if (! $sentAt || $sentAt->greaterThan($now) || empty($data['manual_channel']) || ! ($changes['next_followup_at'] ?? null)) {
                    throw ValidationException::withMessages(['sent_at' => 'Waktu pengiriman aktual tidak boleh future; manual channel dan next follow-up wajib.']);
                }
                if (! empty($data['evidence_id'])) {
                    Evidence::forOrganization($org)->findOrFail($data['evidence_id']);
                }
                $attempt = ContactAttempt::create(['organization_id' => $org->id, 'client_followup_id' => $followup->id, 'actor_user_id' => $actor->id, 'contact_id' => $current->contact_id,
                    'sent_at' => $sentAt, 'recorded_at' => $now, 'manual_channel' => app(PlaintextRenderer::class)->safe($data['manual_channel']), 'template_draft_id' => $current->id,
                    'draft_version' => $current->template_version, 'sent_body' => $current->rendered_body, 'evidence_id' => $data['evidence_id'] ?? null]);
                $changes['state'] = 'contacted';
                app(AuditWriter::class)->write($org, 'followup.contact_recorded', 'ContactAttempt', $attempt->id, 'success', $actor, after: ['followup_id' => $followup->id, 'draft_id' => $current->id, 'channel' => $attempt->manual_channel, 'sent_at' => $sentAt]);
            }
            if ($action === 'waiting_client') {
                if (! ($changes['next_followup_at'] ?? $followup->next_followup_at)) {
                    throw ValidationException::withMessages(['next_followup_at' => 'Waiting client wajib deadline tindak lanjut.']);
                }
                $changes['state'] = 'waiting_client';
            }
            if ($action === 'client_confirmed') {
                if (trim($changes['response_summary'] ?? '') === '') {
                    throw ValidationException::withMessages(['response_summary' => 'Catat jawaban client; konfirmasi belum berarti verified renewal.']);
                }
                $changes['state'] = 'client_confirmed';
            }
            if ($action === 'in_progress') {
                $changes['state'] = 'in_progress';
            }
            if (in_array($action, ['cancel', 'reopen'], true)) {
                if (trim($data['reason'] ?? '') === '') {
                    throw ValidationException::withMessages(['reason' => 'Reason wajib.']);
                }
                $reason = app(PlaintextRenderer::class)->safe($data['reason']);
                $changes['state'] = $action === 'cancel' ? 'cancelled' : 'open';
                $changes['resolution_reason'] = $reason;
                if ($action === 'cancel') {
                    $this->cancelPending($cycle->id);
                }
            }
            if ($action === 'verify_renewal') {
                abort_unless($service->version === (int) ($data['subscription_version'] ?? 0), 409, 'Subscription berubah.');
                abort_unless(isset($data['date_precision'], $data['source']), 422, 'Precision dan sumber baru wajib.');
                $data['source'] = app(PlaintextRenderer::class)->safe($data['source']);
                $normalized = app(Expiry::class)->normalize([...$service->only($service->getFillable()), ...$data, 'renew_by' => $data['renew_by'] ?? null]);
                if (! empty($data['evidence_reference'])) {
                    if (($data['provider_evidence_confirmed'] ?? false) !== true || ! preg_match('/^evidence:[A-Za-z0-9\/_-]{3,200}$/', $data['evidence_reference'])) {
                        throw ValidationException::withMessages(['evidence_reference' => 'Referensi evidence aman dan konfirmasi hasil pemeriksaan masa aktif provider wajib.']);
                    }
                    $evidence = Evidence::create(['organization_id' => $org->id, 'kind' => 'provider_expiry', 'secure_reference' => $data['evidence_reference'], 'source' => $data['source'], 'verified_at' => $now, 'verified_by_user_id' => $actor->id,
                        'metadata' => ['service_subscription_id' => $service->id, 'date_precision' => $normalized['date_precision'], 'expiry_date' => $normalized['expiry_date'] ?? null, 'expires_at' => $normalized['expires_at'] ?? null, 'source_timezone' => $normalized['source_timezone'] ?? null]]);
                } else {
                    $evidence = Evidence::forOrganization($org)->findOrFail($data['evidence_id'] ?? null);
                }
                $oldExpiry = app(Expiry::class)->instant($cycle->expiry_snapshot);
                $newExpiry = app(Expiry::class)->instant($normalized);
                $verifier = $evidence->verified_by_user_id ? User::find($evidence->verified_by_user_id) : null;
                if (! $newExpiry || $newExpiry->lessThanOrEqualTo($now) || ($oldExpiry && $newExpiry->lessThanOrEqualTo($oldExpiry))
                    || $evidence->kind !== 'provider_expiry' || ! $evidence->verified_at || ! $verifier
                    || ! app(OrganizationAuthorizationService::class)->can($verifier, $org, 'renewal.manage')
                    || $evidence->id === ($cycle->expiry_snapshot['evidence_id'] ?? null) || trim($normalized['source'] ?? '') === '') {
                    throw ValidationException::withMessages(['expiry' => 'Expiry baru harus future/later dengan evidence masa aktif provider baru yang diverifikasi; payment/approval bukan renewal.']);
                }
                if ($normalized['renew_by'] && (CarbonImmutable::parse($normalized['renew_by'], 'UTC')->greaterThanOrEqualTo($newExpiry) || CarbonImmutable::parse($normalized['renew_by'], 'UTC')->lessThanOrEqualTo($now))) {
                    throw ValidationException::withMessages(['renew_by' => 'Deadline baru harus future dan sebelum expiry baru.']);
                }
                // Payment state and responsibility are unchanged by technical renewal verification.
                $service->update(['date_precision' => $normalized['date_precision'], 'expiry_date' => $normalized['expiry_date'] ?? null, 'expires_at' => $normalized['expires_at'] ?? null,
                    'source_timezone' => $normalized['source_timezone'] ?? null, 'source' => $normalized['source'], 'evidence_id' => $evidence->id, 'renew_by' => $normalized['renew_by'], 'version' => $service->version + 1]);
                $cycle->update(['state' => 'verified', 'active_subscription_id' => null, 'verified_at' => $now, 'verified_by_user_id' => $actor->id, 'evidence_id' => $evidence->id, 'version' => $cycle->version + 1]);
                $this->cancelPending($cycle->id);
                $newCycle = app(SubscriptionRegistry::class)->cycle($service->fresh());
                $changes['state'] = 'resolved';
                $changes['resolution_reason'] = 'Verified provider expiry; cycle '.$newCycle->sequence;
                $changes['next_followup_at'] = null;
                app(OutboxWriter::class)->record($org, 'renewal.verified', 'service_subscription', $service->id, $service->version, ['renewal_cycle_id' => $cycle->id, 'new_cycle_id' => $newCycle->id, 'followup_id' => $followup->id, 'severity' => 'info', 'impacted_project_ids' => app(RenewalAccess::class)->projectIds($service)]);
            }
            $followup->update([...$changes, 'version' => $followup->version + 1]);
            app(AuditWriter::class)->write($org, 'followup.'.$action, 'ClientFollowup', $followup->id, 'success', $actor, $before, $followup->only(['state', 'version', 'assignee_user_id', 'next_followup_at']), reason: $data['reason'] ?? null);
            if ($action === 'verify_renewal') {
                app(DraftGenerator::class)->generate($org, $followup->fresh(), 'TPL-10', $actor, now: $now);
            }

            return $followup->fresh();
        });
    }

    private function instant(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw ValidationException::withMessages(['datetime' => 'Instant wajib offset timezone.']);
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Exception) {
            throw ValidationException::withMessages(['datetime' => 'Instant invalid.']);
        }
    }

    private function cancelPending(int $cycleId): void
    {
        $ids = RenewalReminder::where('renewal_cycle_id', $cycleId)->where('state', 'pending')->pluck('outbox_event_id')->filter();
        OutboxEvent::whereIn('id', $ids)->where('status', 'pending')->update(['status' => 'cancelled']);
        RenewalReminder::where('renewal_cycle_id', $cycleId)->where('state', 'pending')->update(['state' => 'cancelled']);
        // Delivery cancellation/reconciliation is rechecked against the current cycle by M2-08.
    }
}
