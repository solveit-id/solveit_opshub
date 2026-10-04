<?php

namespace App\Application\TelegramNotifications;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Models\Organization;
use App\Models\TelegramBinding;
use App\Models\TelegramBindingIntent;
use App\Models\TelegramBot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TelegramBindingService
{
    public function start(Organization $org, User $user): array
    {
        app(OrganizationAuthorizationService::class)->require($user, $org, 'organization.read');

        return DB::transaction(function () use ($org, $user) {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            app(OrganizationAuthorizationService::class)->require($user->fresh(), $org->fresh(), 'organization.read');
            $bot = TelegramBot::forOrganization($org)->where('enabled', true)->whereNotNull('identity_verified_at')->firstOrFail();
            TelegramBindingIntent::forOrganization($org)->where('user_id', $user->id)->whereIn('state', ['pending', 'candidate'])->update(['state' => 'cancelled']);
            $token = bin2hex(random_bytes(24));
            $intent = TelegramBindingIntent::create(['organization_id' => $org->id, 'telegram_bot_id' => $bot->id, 'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'identity_version' => $bot->identity_version, 'expires_at' => now('UTC')->addMinutes(10)]);
            app(AuditWriter::class)->write($org, 'telegram.binding_intent', 'TelegramBindingIntent', $intent->id, 'success', $user);

            return ['intent_id' => $intent->id, 'command' => '/start '.$token, 'expires_at' => $intent->expires_at];
        });
    }

    public function candidate(TelegramBot $bot, string $hash, string $telegramUser, string $chatId, string $chatType): bool
    {
        if ($chatType !== 'private' || $chatId !== $telegramUser || ! preg_match('/^[1-9]\d{0,18}$/', $telegramUser)) {
            return false;
        }
        $intent = TelegramBindingIntent::where('telegram_bot_id', $bot->id)->where('token_hash', $hash)->lockForUpdate()->first();
        if (! $intent || $intent->state !== 'pending' || $intent->expires_at->lessThanOrEqualTo(now('UTC')) || $intent->identity_version !== $bot->identity_version) {
            return false;
        }
        $org = Organization::findOrFail($bot->organization_id);
        $user = User::find($intent->user_id);
        if (! $user || ! app(OrganizationAuthorizationService::class)->membership($user, $org)) {
            return false;
        }
        $intent->update(['state' => 'candidate', 'candidate_user_id' => $telegramUser, 'candidate_chat_id' => $chatId, 'claimed_at' => now('UTC')]);

        return true;
    }

    public function confirm(Organization $org, User $user, TelegramBindingIntent $intent, string $expectedId): TelegramBinding
    {
        app(OrganizationAuthorizationService::class)->require($user, $org, 'organization.read');
        abort_unless($intent->organization_id === $org->id && $intent->user_id === $user->id, 404);

        return DB::transaction(function () use ($org, $user, $intent, $expectedId) {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            app(OrganizationAuthorizationService::class)->require($user->fresh(), $org->fresh(), 'organization.read');
            $intent = TelegramBindingIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();
            $bot = TelegramBot::whereKey($intent->telegram_bot_id)->firstOrFail();
            abort_unless($intent->state === 'candidate' && $intent->expires_at->greaterThan(now('UTC')) && $bot->enabled && $intent->identity_version === $bot->identity_version && $intent->candidate_user_id === $expectedId, 409, 'Intent kedaluwarsa/berubah atau ID Telegram tidak cocok.');
            abort_unless(! TelegramBinding::where('telegram_bot_id', $bot->id)->where('telegram_user_id', $expectedId)->where('user_id', '!=', $user->id)->exists(), 409, 'Telegram identity sudah bound.');
            $binding = TelegramBinding::updateOrCreate(['telegram_bot_id' => $bot->id, 'user_id' => $user->id], ['organization_id' => $org->id, 'telegram_user_id' => $expectedId, 'chat_id' => $intent->candidate_chat_id, 'identity_version' => $bot->identity_version, 'enabled' => true, 'confirmed_at' => now('UTC')]);
            $intent->update(['state' => 'confirmed', 'confirmed_at' => now('UTC')]);
            app(AuditWriter::class)->write($org, 'telegram.binding_confirmed', 'TelegramBinding', $binding->id, 'success', $user, after: ['telegram_user_id' => $expectedId]);

            return $binding;
        });
    }

    public function current(TelegramBot $bot, string $telegramUser): ?TelegramBinding
    {
        if (! $bot->enabled || ! $bot->identity_verified_at) {
            return null;
        }
        $binding = TelegramBinding::where('telegram_bot_id', $bot->id)->where('telegram_user_id', $telegramUser)->where('enabled', true)->where('identity_version', $bot->identity_version)->first();
        $org = Organization::findOrFail($bot->organization_id);
        $user = $binding ? User::find($binding->user_id) : null;

        return $user && app(OrganizationAuthorizationService::class)->membership($user, $org) ? $binding : null;
    }
}
