<?php

namespace App\Application\TelegramNotifications;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\ClientTemplates\PlaintextRenderer;
use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Application\IdentityAccess\ProjectAccess;
use App\Domain\IdentityAccess\Role;
use App\Infrastructure\Telegram\NativeTelegramTransport;
use App\Infrastructure\Telegram\TelegramSecretResolver;
use App\Infrastructure\Telegram\TelegramTransport;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TelegramConfiguration
{
    public function owner(Organization $org, User $actor): void
    {
        abort_unless(app(OrganizationAuthorizationService::class)->membership($actor, $org)?->role === Role::Owner, 403);
    }

    public function bot(Organization $org, User $actor, array $data): TelegramBot
    {
        $this->owner($org, $actor);
        $data = validator($data, ['name' => ['required', 'string', 'max:100'], 'token_secret_reference' => ['required', 'regex:/^env:OPSHUB_TELEGRAM_BOT_TOKEN[A-Z0-9_]{0,80}$/'],
            'webhook_secret_reference' => ['required', 'regex:/^env:OPSHUB_TELEGRAM_WEBHOOK_SECRET[A-Z0-9_]{0,80}$/'], 'version' => ['required', 'integer', 'min:0'], 'enabled' => ['required', 'boolean'],
            'timezone' => ['required', 'timezone:all'], 'digest_time' => ['required', 'date_format:H:i'], 'quiet_start' => ['required', 'date_format:H:i'], 'quiet_end' => ['required', 'date_format:H:i']])->validate();
        $data['name'] = app(PlaintextRenderer::class)->safe($data['name']);

        return DB::transaction(function () use ($org, $actor, $data) {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $bot = TelegramBot::forOrganization($org)->lockForUpdate()->first();
            abort_unless(($bot?->version ?? 0) === $data['version'], 409);
            $rotated = $bot && ($bot->token_secret_reference !== $data['token_secret_reference'] || $bot->webhook_secret_reference !== $data['webhook_secret_reference']);
            abort_unless(! $data['enabled'] || ($bot?->identity_verified_at && ! $rotated && (! $bot->identity_fake || app()->environment('testing'))), 422, 'Test bot identity sebelum enable; rotated token harus diverifikasi ulang.');
            $settings = [...($rotated ? [] : ($bot?->settings ?? [])), 'timezone' => $data['timezone'], 'digest_time' => $data['digest_time'], 'quiet_start' => $data['quiet_start'], 'quiet_end' => $data['quiet_end'], 'group_per_minute' => 15, 'private_per_second' => 1, 'bot_per_second' => 20];
            $values = ['name' => $data['name'], 'token_secret_reference' => $data['token_secret_reference'], 'webhook_secret_reference' => $data['webhook_secret_reference'], 'enabled' => $data['enabled'], 'settings' => $settings, 'version' => ($bot?->version ?? 0) + 1];
            if ($bot) {
                if ($rotated) {
                    $values = [...$values, 'identity_version' => $bot->identity_version + 1, 'external_bot_id' => null, 'identity_verified_at' => null, 'identity_fake' => false];
                    TelegramDestination::forOrganization($org)->where('telegram_bot_id', $bot->id)->update(['enabled' => false]);
                }
                $bot->update($values);
            } else {
                $bot = TelegramBot::create(['organization_id' => $org->id, ...$values]);
            }
            app(AuditWriter::class)->write($org, 'telegram.bot_configured', 'TelegramBot', $bot->id, 'success', $actor, after: ['version' => $bot->version, 'enabled' => $bot->enabled, 'identity_rotated' => (bool) $rotated]);

            return $bot->fresh();
        });
    }

    public function identity(Organization $org, User $actor, int $version): array
    {
        $this->owner($org, $actor);
        $bot = TelegramBot::forOrganization($org)->firstOrFail();
        abort_unless($bot->version === $version, 409);
        $result = app(TelegramTransport::class)->request($bot, 'getMe');
        if ($result->outcome !== 'accepted' || ! $result->botId || ($result->fake && ! app()->environment('testing'))) {
            return ['status' => $result->outcome, 'code' => $result->code, 'fake' => $result->fake];
        }
        DB::transaction(function () use ($org, $actor, $bot, $version, $result) {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $this->owner($org->fresh(), $actor->fresh());
            $locked = TelegramBot::whereKey($bot->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $version, 409);
            $locked->update(['external_bot_id' => $result->botId, 'identity_verified_at' => now('UTC'), 'identity_fake' => $result->fake, 'version' => $version + 1]);
            app(AuditWriter::class)->write($org, 'telegram.identity_verified', 'TelegramBot', $bot->id, 'success', $actor, after: ['bot_id' => $result->botId, 'fake' => $result->fake]);
        });

        return ['status' => 'identity_verified', 'external_bot_id' => $result->botId, 'fake' => $result->fake];
    }

    public function destination(Organization $org, User $actor, array $data, ?TelegramDestination $dest = null): TelegramDestination
    {
        $this->owner($org, $actor);
        $data = validator($data, ['label' => ['required', 'string', 'max:100'], 'chat_id' => ['required', 'regex:/^-?[1-9]\d{0,18}$/'], 'chat_type' => ['required', Rule::in(['private', 'group', 'supergroup'])],
            'project_ids' => ['present', 'array'], 'project_ids.*' => ['integer', 'distinct'], 'all_projects' => ['required', 'boolean'], 'owner_route' => ['required', 'boolean'], 'severities' => ['required', 'array', 'min:1'],
            'severities.*' => [Rule::in(['critical', 'warning', 'info']), 'distinct'], 'scope_confirmed' => ['accepted'], 'member_user_id' => ['nullable', 'integer'], 'enabled' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:0']])->validate();
        $data['label'] = app(PlaintextRenderer::class)->safe($data['label']);
        abort_unless($data['all_projects'] || $data['project_ids'] !== [], 422, 'Scope proyek wajib.');
        abort_unless(Project::forOrganization($org)->whereIn('id', $data['project_ids'])->count() === count($data['project_ids']), 422);
        if ($data['chat_type'] === 'private') {
            abort_unless(! empty($data['member_user_id']) && (int) $data['chat_id'] > 0, 422, 'Private destination membutuhkan application member, bukan contact bisnis.');
            $member = User::findOrFail($data['member_user_id']);
            abort_unless(app(OrganizationAuthorizationService::class)->membership($member, $org), 422);
            $ids = $data['all_projects'] ? Project::forOrganization($org)->pluck('id')->all() : $data['project_ids'];
            abort_unless(app(ProjectAccess::class)->owner($member, $org) || app(ProjectAccess::class)->query($member, $org)->whereIn('id', $ids)->count() === count($ids), 422, 'Private recipient tidak memiliki seluruh scope destination.');
            if ($data['owner_route']) {
                abort_unless(app(ProjectAccess::class)->owner($member, $org), 422);
            }
        } else {
            abort_unless((int) $data['chat_id'] < 0 && empty($data['member_user_id']), 422);
        }

        return DB::transaction(function () use ($org, $actor, $data, $dest) {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $bot = TelegramBot::forOrganization($org)->firstOrFail();
            if ($dest) {
                $dest = TelegramDestination::forOrganization($org)->whereKey($dest->id)->lockForUpdate()->firstOrFail();
                abort_unless($dest->version === $data['version'], 409);
                abort_unless($dest->chat_id === $data['chat_id'] && $dest->chat_type === $data['chat_type'], 422, 'Chat identity immutable; buat destination baru.');
            } else {
                abort_unless($data['version'] === 0, 409);
            }
            unset($data['scope_confirmed']);
            $values = [...$data, 'scope_confirmed_by' => $actor->id, 'scope_confirmed_at' => now('UTC'), 'version' => ($dest?->version ?? 0) + 1];
            $dest ? $dest->update($values) : $dest = TelegramDestination::create(['organization_id' => $org->id, 'telegram_bot_id' => $bot->id, ...$values]);
            app(AuditWriter::class)->write($org, 'telegram.destination_configured', 'TelegramDestination', $dest->id, 'success', $actor, after: $dest->only(['version', 'enabled', 'project_ids', 'all_projects', 'owner_route', 'chat_type']));

            return $dest->fresh();
        });
    }

    public function webhook(Organization $org, User $actor, int $version): array
    {
        $this->owner($org, $actor);
        $bot = TelegramBot::forOrganization($org)->firstOrFail();
        abort_unless($bot->version === $version && $bot->enabled && $bot->identity_verified_at, 409);
        if (! config('opshub.live_connectors_enabled') && app(TelegramTransport::class) instanceof NativeTelegramTransport) {
            return ['status' => 'unavailable', 'code' => 'LIVE_DISABLED'];
        }
        $url = rtrim(config('app.url'), '/');
        $parsed = parse_url($url);
        abort_unless(($parsed['scheme'] ?? '') === 'https' && isset($parsed['host']) && ! isset($parsed['user']) && ! isset($parsed['pass']) && ! isset($parsed['query']) && ! isset($parsed['fragment']), 422, 'Webhook membutuhkan APP_URL HTTPS yang aman.');
        try {
            $secret = app(TelegramSecretResolver::class)->resolve($bot->webhook_secret_reference);
        } catch (\Throwable) {
            abort(422, 'Webhook secret reference tidak tersedia.');
        }
        abort_unless(preg_match('/^[A-Za-z0-9_-]{1,256}$/', $secret), 422);
        $result = app(TelegramTransport::class)->request($bot, 'setWebhook', ['url' => $url.'/api/telegram/webhook/'.$bot->id, 'secret_token' => $secret, 'allowed_updates' => ['message', 'callback_query'], 'drop_pending_updates' => false]);
        if ($result->outcome === 'accepted') {
            DB::transaction(function () use ($org, $actor, $bot, $version, $result) {
                Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
                $this->owner($org->fresh(), $actor->fresh());
                $locked = TelegramBot::whereKey($bot->id)->lockForUpdate()->firstOrFail();
                abort_unless($locked->version === $version, 409);
                $locked->update(['settings' => [...$locked->settings, 'webhook_configured_at' => now('UTC')->toIso8601String(), 'webhook_identity_version' => $locked->identity_version, 'webhook_fake' => $result->fake], 'version' => $version + 1]);
                app(AuditWriter::class)->write($org, 'telegram.webhook_configured', 'TelegramBot', $bot->id, 'success', $actor, after: ['fake' => $result->fake]);
            });
        }

        return ['status' => $result->outcome, 'code' => $result->code, 'fake' => $result->fake];
    }

    public function testIntent(Organization $org, User $actor, TelegramDestination $dest, int $version): int
    {
        $this->owner($org, $actor);
        abort_unless($dest->organization_id === $org->id, 404);

        return DB::transaction(function () use ($org, $actor, $dest, $version) {
            $dest = TelegramDestination::forOrganization($org)->whereKey($dest->id)->lockForUpdate()->firstOrFail();
            abort_unless($dest->version === $version, 409);
            $dest->update(['version' => $version + 1]);
            $event = app(OutboxWriter::class)->record($org, 'telegram.test_requested', 'telegram_destination', $dest->id, $dest->version, ['destination_id' => $dest->id, 'severity' => 'critical', 'requested_by' => $actor->id, 'impacted_project_ids' => $dest->project_ids]);
            app(AuditWriter::class)->write($org, 'telegram.test_requested', 'OutboxEvent', $event->id, 'success', $actor);

            return $event->id;
        });
    }
}
