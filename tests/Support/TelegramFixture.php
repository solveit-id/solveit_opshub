<?php

namespace Tests\Support;

use App\Application\TelegramNotifications\TelegramConfiguration;
use App\Infrastructure\Telegram\TelegramTransport;
use App\Infrastructure\Testing\ScriptedTelegramTransport;

trait TelegramFixture
{
    protected function telegramGraph(array $expiry = []): array
    {
        [$org, $owner, $service, $project, $contact] = $this->renewalGraph($expiry);
        $fake = new ScriptedTelegramTransport;
        app()->instance(TelegramTransport::class, $fake);
        $configuration = app(TelegramConfiguration::class);
        $data = $this->botData();
        $bot = $configuration->bot($org, $owner, $data);
        $configuration->identity($org, $owner, $bot->version);
        $bot = $configuration->bot($org, $owner, [...$data, 'version' => $bot->fresh()->version, 'enabled' => true]);
        $dest = $configuration->destination($org, $owner, $this->destinationData([$project->id]));

        return [$org, $owner, $service, $project, $contact, $bot, $dest, $fake];
    }

    protected function botData(): array
    {
        return ['version' => 0, 'name' => 'Fictitious bot', 'enabled' => false, 'token_secret_reference' => 'env:OPSHUB_TELEGRAM_BOT_TOKEN', 'webhook_secret_reference' => 'env:OPSHUB_TELEGRAM_WEBHOOK_SECRET', 'timezone' => 'Asia/Jakarta', 'digest_time' => '08:00', 'quiet_start' => '22:00', 'quiet_end' => '07:00'];
    }

    protected function destinationData(array $projectIds): array
    {
        return ['version' => 0, 'label' => 'Fictitious operations group', 'chat_id' => '-1000000001', 'chat_type' => 'supergroup', 'project_ids' => $projectIds, 'all_projects' => false, 'owner_route' => true, 'severities' => ['critical', 'warning', 'info'], 'scope_confirmed' => true, 'member_user_id' => null, 'enabled' => true];
    }
}
