<?php

namespace App\Application\TelegramNotifications;

use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Application\IdentityAccess\ProjectAccess;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TelegramBinding;
use App\Models\TelegramBot;
use App\Models\TelegramDelivery;
use App\Models\TelegramDestination;
use App\Models\TelegramUpdateReceipt;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

class DeliveryRecipients
{
    public function resolve(TelegramDelivery $d, TelegramBot $bot, Organization $org): ?array
    {
        if (! $org->is_active || ! $bot->enabled || ! $bot->identity_verified_at || ($bot->identity_fake && ! app()->environment('testing'))) {
            return null;
        }
        if ($d->telegram_destination_id) {
            $dest = TelegramDestination::forOrganization($org)->where('telegram_bot_id', $bot->id)->find($d->telegram_destination_id);
            if (! $dest?->enabled) {
                return null;
            }
            $scope = $dest->all_projects ? Project::forOrganization($org)->pluck('id')->all() : $dest->project_ids;
            if (array_diff($d->project_ids, $scope) !== []) {
                return null;
            }
            if ($dest->chat_type === 'private' && ! $this->member($org, $dest->member_user_id, $d->project_ids)) {
                return null;
            }

            return ['chat_id' => $dest->chat_id, 'chat_type' => $dest->chat_type];
        }
        if ($d->binding_id) {
            $binding = TelegramBinding::forOrganization($org)->where('telegram_bot_id', $bot->id)->find($d->binding_id);
            if (! $binding?->enabled || $binding->identity_version !== $bot->identity_version || ! $this->member($org, $binding->user_id, $d->project_ids)) {
                return null;
            }

            return ['chat_id' => $binding->chat_id, 'chat_type' => 'private'];
        }
        // The only unbound output is a generic help/binding notice from its authenticated durable receipt.
        if ($d->notification_kind !== 'binding_notice' || $d->project_ids !== []) {
            return null;
        }
        $receipt = TelegramUpdateReceipt::forOrganization($org)->where('telegram_bot_id', $bot->id)->find($d->receipt_id);
        if (! $receipt || $receipt->identity_version !== $bot->identity_version) {
            return null;
        }
        $payload = json_decode(Crypt::decryptString($receipt->encrypted_payload), true, flags: JSON_THROW_ON_ERROR);
        if ($payload['chat_type'] !== 'private' || $payload['chat_id'] !== $payload['telegram_user_id']) {
            return null;
        }

        return ['chat_id' => $payload['chat_id'], 'chat_type' => 'private'];
    }

    private function member(Organization $org, ?int $id, array $projects): bool
    {
        $user = $id ? User::find($id) : null;

        return $user && app(OrganizationAuthorizationService::class)->membership($user, $org) && (app(ProjectAccess::class)->owner($user, $org) || app(ProjectAccess::class)->query($user, $org)->whereIn('id', $projects)->count() === count($projects));
    }
}
