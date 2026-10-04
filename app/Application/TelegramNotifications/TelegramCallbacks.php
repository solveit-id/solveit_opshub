<?php

namespace App\Application\TelegramNotifications;

use App\Application\ClientTemplates\DraftGenerator;
use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Application\IdentityAccess\ProjectAccess;
use App\Application\Monitoring\IncidentActions;
use App\Application\RenewalFollowups\FollowupWorkflow;
use App\Application\RenewalFollowups\RenewalAccess;
use App\Models\ClientFollowup;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\TelegramBinding;
use App\Models\TelegramBot;
use App\Models\TelegramCallbackReference;
use App\Models\TelegramDestination;
use App\Models\TelegramUpdateReceipt;
use App\Models\User;

class TelegramCallbacks
{
    public function buttons(OutboxEvent $event, TelegramDestination $destination): array
    {
        $type = isset($event->payload['followup_id']) ? 'followup' : ($event->aggregate_type === 'incident' ? 'incident' : null);
        if (! $type) {
            return [];
        }
        $entity = $type === 'followup' ? ClientFollowup::forOrganization($event->organization_id)->find($event->payload['followup_id']) : Incident::forOrganization($event->organization_id)->find($event->aggregate_id);
        if (! $entity || in_array($entity->state, ['resolved', 'closed', 'cancelled'], true)) {
            return [];
        }
        $projects = $type === 'followup' ? app(RenewalAccess::class)->projectIds($entity->cycle->subscription)->all() : $entity->projects()->pluck('projects.id')->all();
        $bot = TelegramBot::findOrFail($destination->telegram_bot_id);
        $buttons = [];
        foreach (['acknowledge' => 'Acknowledge', 'claim' => 'Ambil tugas', ...($type === 'followup' ? ['template' => 'Template terbaru'] : [])] as $action => $label) {
            $reference = bin2hex(random_bytes(24));
            TelegramCallbackReference::create(['organization_id' => $event->organization_id, 'telegram_bot_id' => $bot->id, 'telegram_destination_id' => $destination->id, 'reference_hash' => hash('sha256', $reference), 'action' => $action, 'aggregate_type' => $type, 'aggregate_id' => $entity->id, 'aggregate_version' => $entity->version, 'project_ids' => $projects, 'identity_version' => $bot->identity_version, 'expires_at' => now('UTC')->addHour()]);
            $buttons[] = ['text' => $label, 'callback_data' => $reference];
        }

        return $buttons;
    }

    public function act(TelegramBot $bot, TelegramBinding $binding, TelegramUpdateReceipt $receipt, array $payload): string
    {
        $ref = TelegramCallbackReference::where('telegram_bot_id', $bot->id)->where('reference_hash', $payload['reference_hash'])->lockForUpdate()->first();
        abort_unless($ref && $ref->expires_at->greaterThan(now('UTC')) && $ref->identity_version === $bot->identity_version && in_array($ref->action, ['acknowledge', 'claim', 'template'], true), 403);
        $dest = $ref->telegram_destination_id ? TelegramDestination::find($ref->telegram_destination_id) : null;
        abort_unless($dest?->enabled && $dest->chat_id === $payload['chat_id'], 403);
        $org = Organization::findOrFail($bot->organization_id);
        $actor = User::findOrFail($binding->user_id);
        $access = app(ProjectAccess::class);
        $scope = $dest->all_projects ? $access->query($actor, $org)->pluck('id')->all() : $dest->project_ids;
        abort_unless(array_diff($ref->project_ids, $scope) === [] && ($access->owner($actor, $org) || $access->query($actor, $org)->whereIn('id', $ref->project_ids)->count() === count($ref->project_ids)), 403);
        if ($ref->aggregate_type === 'followup') {
            $entity = ClientFollowup::forOrganization($org)->findOrFail($ref->aggregate_id);
            app(RenewalAccess::class)->require($actor, $org, $entity->cycle->subscription, 'followup.manage');
            abort_unless(app(RenewalAccess::class)->projectIds($entity->cycle->subscription)->diff($ref->project_ids)->isEmpty(), 409);
        } else {
            $entity = Incident::forOrganization($org)->findOrFail($ref->aggregate_id);
            app(OrganizationAuthorizationService::class)->require($actor, $org, 'incident.manage');
            abort_unless($entity->projects()->pluck('projects.id')->diff($ref->project_ids)->isEmpty(), 409);
        }
        // Permission checks precede replay; revocation never gains mutation via a previous receipt.
        if ($ref->used_at) {
            return 'CALLBACK_ALREADY_USED';
        }
        if ($ref->action === 'template') {
            $draft = app(DraftGenerator::class)->generate($org, $entity, $entity->cycle->state === 'verified' ? 'TPL-10' : null, $actor);
            $text = $draft->draft_status === 'ready' ? $draft->rendered_body : 'Draft blocked: '.implode(', ', $draft->blocked_reasons)."\n".app(TelegramMessages::class)->link($org, OutboxEvent::make(['payload' => ['followup_id' => $entity->id]]));
            app(TelegramPrivateReply::class)->write($receipt, $payload, $text, $binding, $ref->project_ids, ['followup_id' => $entity->id, 'template_key' => $draft->template_key]);
        } else {
            abort_unless($entity->version === $ref->aggregate_version && ! in_array($entity->state, ['resolved', 'closed', 'cancelled'], true), 409);
            if ($ref->aggregate_type === 'followup') {
                app(FollowupWorkflow::class)->act($org, $actor, $entity, $ref->action, ['version' => $entity->version]);
            } else {
                app(IncidentActions::class)->change($entity, $actor, $ref->action === 'claim' ? 'assign' : 'acknowledge', $entity->version, ['assignee_user_id' => $actor->id]);
            }
        }
        $ref->update(['used_by_user_id' => $actor->id, 'used_at' => now('UTC')]);

        return 'CALLBACK_APPLIED';
    }
}
