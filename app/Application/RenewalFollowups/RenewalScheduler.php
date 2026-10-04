<?php

namespace App\Application\RenewalFollowups;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\TelegramNotifications\OutboxWriter;
use App\Models\ClientFollowup;
use App\Models\Evidence;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RenewalCycle;
use App\Models\RenewalReminder;
use App\Models\ServiceSubscription;
use App\Models\User;
use App\Rules\RejectSecretBearingValue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RenewalScheduler
{
    public function tick(Organization $org, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');

        return DB::transaction(function () use ($org, $now): array {
            $org = Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $events = [];
            if (! $org->is_active) {
                return $events;
            }
            foreach (ServiceSubscription::forOrganization($org)->lockForUpdate()->get() as $service) {
                $ids = Project::forOrganization($org)->whereIn('id', app(RenewalAccess::class)->projectIds($service))->where('lifecycle', 'active')->pluck('id')->all();
                if ($ids === []) {
                    continue;
                }
                $cycle = app(SubscriptionRegistry::class)->cycle($service);
                $snapshot = $cycle->expiry_snapshot;
                $at = app(Expiry::class)->instant($snapshot);
                $verified = isset($snapshot['evidence_id']) && Evidence::forOrganization($org)->whereKey($snapshot['evidence_id'])->where('kind', 'provider_expiry')->whereNotNull('verified_at')->whereNotNull('verified_by_user_id')->exists();
                if ($at === null || ! $verified) {
                    $followup = $this->followup($cycle, 'verify_expiry');
                    $events[] = $this->emit($org, $cycle, $followup, -1, 'verification', 'warning', $ids, $now);

                    continue;
                }
                $zone = $snapshot['source_timezone'] ?? $org->timezone ?? 'Asia/Jakarta';
                $days = (int) $now->setTimezone($zone)->startOfDay()->diffInDays($at->setTimezone($zone)->startOfDay(), false);
                $leads = array_values(array_unique([0, ...($service->reminder_policy['lead_days'] ?? [60, 30, 14, 7, 3, 1, 0])]));
                rsort($leads);
                $applicable = array_filter($leads, fn ($lead) => $days <= $lead);
                if ($applicable === []) {
                    continue;
                }
                $current = min($applicable);
                $severity = $days <= 7 ? 'critical' : ($days <= 30 ? 'warning' : 'info');
                $followup = $this->followup($cycle, 'renewal');
                if ($followup->purpose === 'verify_expiry') {
                    $followup->update(['purpose' => 'renewal', 'version' => $followup->version + 1]);
                }
                if ($followup->snoozed_until?->greaterThan($now)) {
                    // A long warning snooze cannot silently suppress newly critical work.
                    if ($severity !== 'critical' || $followup->snoozed_until->lessThanOrEqualTo($now->addHours(24))) {
                        continue;
                    }
                    $followup->update(['snoozed_until' => $now->addHours(24)]);

                    continue;
                }
                foreach ($applicable as $lead) {
                    if ($lead === $current) {
                        continue;
                    }
                    RenewalReminder::firstOrCreate(['renewal_cycle_id' => $cycle->id, 'kind' => 'threshold', 'threshold' => $lead], [
                        'organization_id' => $org->id, 'severity' => $severity, 'state' => 'skipped', 'impacted_project_ids' => $ids, 'occurred_at' => $now,
                    ]);
                }
                $events[] = $this->emit($org, $cycle, $followup, $current, 'threshold', $severity, $ids, $now);
                $criticalAt = RenewalReminder::where('renewal_cycle_id', $cycle->id)->where('severity', 'critical')->where('kind', 'threshold')->where('state', 'pending')->min('occurred_at');
                if ($severity === 'critical' && $criticalAt && $followup->acknowledged_at === null) {
                    foreach ([15, 60] as $minutes) {
                        if (CarbonImmutable::parse($criticalAt, 'UTC')->addMinutes($minutes)->lessThanOrEqualTo($now)) {
                            $events[] = $this->emit($org, $cycle, $followup, $minutes, 'escalation', 'critical', $ids, $now);
                        }
                    }
                }
                if ($followup->overdue($now)) {
                    // One event per overdue calendar day, not an alert every scheduler tick.
                    $events[] = $this->emit($org, $cycle, $followup, (int) $now->setTimezone($zone)->format('Ymd'), 'overdue', $severity, $ids, $now);
                }
            }

            return array_values(array_filter($events));
        });
    }

    public function snooze(Organization $org, User $actor, ServiceSubscription $service, int $version, CarbonImmutable $until, string $reason, ?CarbonImmutable $now = null): ClientFollowup
    {
        $now ??= CarbonImmutable::now('UTC');
        app(RenewalAccess::class)->require($actor, $org, $service, 'followup.manage');

        return DB::transaction(function () use ($org, $actor, $service, $version, $until, $reason, $now): ClientFollowup {
            ServiceSubscription::whereKey($service->id)->lockForUpdate()->firstOrFail();
            $cycle = app(SubscriptionRegistry::class)->cycle($service);
            $followup = ClientFollowup::where('renewal_cycle_id', $cycle->id)->lockForUpdate()->firstOrFail();
            app(RenewalAccess::class)->require($actor, $org, $service, 'followup.manage');
            abort_unless($followup->version === $version, 409);
            $expiry = app(Expiry::class)->instant($cycle->expiry_snapshot);
            $zone = $cycle->expiry_snapshot['source_timezone'] ?? $org->timezone;
            $critical = $expiry && $now->setTimezone($zone)->startOfDay()->diffInDays($expiry->setTimezone($zone)->startOfDay(), false) <= 7;
            if (trim($reason) === '' || $until->lessThanOrEqualTo($now) || $until->greaterThan($now->addHours($critical ? 24 : 168))) {
                throw ValidationException::withMessages(['snoozed_until' => 'Reason dan batas akhir wajib; critical maksimal 24 jam, lainnya 7 hari.']);
            }
            validator(['reason' => $reason], ['reason' => ['string', 'max:2000', new RejectSecretBearingValue]])->validate();
            $followup->update(['snoozed_until' => $until, 'snooze_reason' => $reason, 'snoozed_by_user_id' => $actor->id, 'version' => $followup->version + 1]);
            app(AuditWriter::class)->write($org, 'followup.snoozed', 'ClientFollowup', $followup->id, 'success', $actor, after: $followup->only(['snoozed_until', 'version']), reason: $reason);

            return $followup->fresh();
        });
    }

    public function followup(RenewalCycle $cycle, string $purpose): ClientFollowup
    {
        return ClientFollowup::firstOrCreate(['renewal_cycle_id' => $cycle->id], ['organization_id' => $cycle->organization_id, 'purpose' => $purpose])->fresh();
    }

    private function emit(Organization $org, RenewalCycle $cycle, ClientFollowup $followup, int $threshold, string $kind, string $severity, array $ids, CarbonImmutable $now): ?int
    {
        if (RenewalReminder::where('renewal_cycle_id', $cycle->id)->where('kind', $kind)->where('threshold', $threshold)->exists() || in_array($followup->state, ['resolved', 'cancelled'], true)) {
            return null;
        }
        $followup->update(['severity' => $severity, 'version' => $followup->version + 1]);
        $event = app(OutboxWriter::class)->record($org, $kind === 'verification' ? 'renewal.verification_required' : 'renewal.reminder', 'client_followup', $followup->id, $followup->version, [
            'service_subscription_id' => $cycle->service_subscription_id, 'renewal_cycle_id' => $cycle->id, 'followup_id' => $followup->id,
            'kind' => $kind, 'threshold' => $threshold, 'severity' => $severity, 'impacted_project_ids' => $ids,
            'action_owner' => $cycle->subscription->action_owner, 'occurred_at' => $now->toIso8601String(), 'route' => $kind === 'escalation' ? 'owner' : 'severity',
        ]);
        $reminder = RenewalReminder::create(['organization_id' => $org->id, 'renewal_cycle_id' => $cycle->id, 'threshold' => $threshold, 'kind' => $kind, 'severity' => $severity, 'state' => 'pending', 'impacted_project_ids' => $ids, 'outbox_event_id' => $event->id, 'occurred_at' => $now]);

        return $reminder->id;
    }
}
