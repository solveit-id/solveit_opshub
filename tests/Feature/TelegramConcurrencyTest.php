<?php

namespace Tests\Feature;

use App\Models\ClientFollowup;
use App\Models\Evidence;
use App\Models\RenewalCycle;
use App\Models\RenewalReminder;
use App\Models\TelegramDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\Support\RenewalFixture;
use Tests\Support\TelegramFixture;
use Tests\TestCase;

class TelegramConcurrencyTest extends TestCase
{
    use DatabaseMigrations, RenewalFixture, TelegramFixture;

    public function test_two_mysql_processes_preserve_canonical_reminder_delivery_lease_and_active_cycle(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04T03:00:00Z'));
        [$org, $owner, $service] = $this->telegramGraph(['expiry_date' => '2026-10-18']);
        $this->race('schedule', $service->id);
        $this->assertDatabaseCount('renewal_reminders', 3);
        $this->assertSame(1, RenewalReminder::where('state', 'pending')->count());
        $this->assertSame(2, RenewalReminder::where('state', 'skipped')->count());
        $this->assertDatabaseCount('client_followups', 1);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->race('materialize', $org->id);
        $this->assertDatabaseCount('telegram_deliveries', 2);
        $header = TelegramDelivery::where('notification_kind', 'internal')->sole();
        $this->race('send', $header->id);
        $this->assertSame('sent', $header->fresh()->state);
        $this->assertSame(1, $header->fresh()->attempts);
        $this->assertDatabaseCount('telegram_delivery_attempts', 1);
        Evidence::create(['organization_id' => $org->id, 'kind' => 'provider_expiry', 'source' => 'fictitious_race_new_period', 'verified_at' => now('UTC'), 'verified_by_user_id' => $owner->id]);
        $followup = ClientFollowup::sole();
        $results = $this->race('verify', $followup->id);
        sort($results);
        $this->assertSame(['conflict', 'verified'], $results);
        $this->assertSame('resolved', $followup->fresh()->state);
        $this->assertDatabaseCount('renewal_cycles', 2);
        $this->assertSame(1, RenewalCycle::whereNotNull('active_subscription_id')->count());
        $this->assertSame('unknown', $service->fresh()->payment_status);
    }

    private function race(string $operation, int $id): array
    {
        $connection = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LIVE_CONNECTORS_ENABLED' => 'false', 'OPSHUB_PUBLIC_PROBES_ENABLED' => 'false'];
        $ready = (string) (microtime(true) + 2);
        $processes = [];
        for ($n = 0; $n < 2; $n++) {
            $process = new Process([PHP_BINARY, base_path('scripts/telegram-race-worker.php'), $operation, (string) $id, $ready], base_path(), $environment, timeout: 30);
            $process->start();
            $processes[] = $process;
        }

        return array_map(function (Process $process): string {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

            return trim($process->getOutput());
        }, $processes);
    }
}
