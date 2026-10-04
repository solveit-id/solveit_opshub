<?php

namespace Tests\Unit;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\IdentityAccess\InitialOwnerService;
use App\Application\PolicyScheduling\IdempotencyConflict;
use App\Application\PolicyScheduling\IdempotencyService;
use App\Application\PolicyScheduling\JobSlotService;
use App\Application\TelegramNotifications\OutboxWriter;
use App\Domain\IdentityAccess\Role;
use App\Infrastructure\Connectors\Fakes\FakeCpanelAdapter;
use App\Infrastructure\Connectors\Fakes\FakeSftpAdapter;
use App\Infrastructure\Connectors\Fakes\FakeTelegramTransport;
use App\Infrastructure\Security\CsvValueEscaper;
use App\Infrastructure\Security\HostResolver;
use App\Infrastructure\Security\LiveConnectorGate;
use App\Infrastructure\Security\OutboundTargetValidator;
use App\Infrastructure\Security\WebhookSignatureVerifier;
use App\Infrastructure\Testing\FakeClock;
use App\Infrastructure\Testing\FakeObjectStorage;
use App\Infrastructure\Testing\FakeQueue;
use App\Jobs\DispatchPendingOutboxEvents;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class FoundationReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbox_is_rolled_back_with_its_business_transaction(): void
    {
        $organization = Organization::create(['name' => 'Solveit', 'timezone' => 'Asia/Jakarta']);
        $writer = app(OutboxWriter::class);

        try {
            DB::transaction(function () use ($organization, $writer): void {
                $writer->record($organization, 'foundation.changed', 'organization', $organization->id, 1, ['token' => 'secret-value']);
                throw new LogicException('rollback');
            });
        } catch (LogicException) {
        }

        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_outbox_prevents_duplicate_aggregate_version_events(): void
    {
        $organization = Organization::create(['name' => 'Solveit', 'timezone' => 'Asia/Jakarta']);
        $writer = app(OutboxWriter::class);
        $writer->record($organization, 'foundation.changed', 'organization', $organization->id, 1);

        $this->expectException(QueryException::class);
        $writer->record($organization, 'foundation.changed', 'organization', $organization->id, 1);
    }

    public function test_job_slot_and_idempotency_are_stable(): void
    {
        $organization = Organization::create(['name' => 'Solveit', 'timezone' => 'Asia/Jakarta']);
        $user = User::factory()->create();
        $slot = now()->startOfMinute();
        $jobSlots = app(JobSlotService::class);
        $first = $jobSlots->reserve($organization, 'foundation.check', $slot);
        $second = $jobSlots->reserve($organization, 'foundation.check', $slot);

        $this->assertSame($first->id, $second->id);

        $idempotency = app(IdempotencyService::class);
        $record = $idempotency->remember($organization, $user, 'foundation.action', 'key-1', 'hash-a', ['state' => 'queued'], now()->addDay());
        $replay = $idempotency->remember($organization, $user, 'foundation.action', 'key-1', 'hash-a', ['state' => 'ignored'], now()->addDay());

        $this->assertSame($record->id, $replay->id);
        $this->expectException(IdempotencyConflict::class);
        $idempotency->remember($organization, $user, 'foundation.action', 'key-1', 'hash-b', ['state' => 'queued'], now()->addDay());
    }

    public function test_initial_owner_bootstrap_is_one_time_and_creates_a_scoped_membership(): void
    {
        $user = app(InitialOwnerService::class)->bootstrap('OpsHub Test', 'Owner', 'owner@example.test', 'Secret-password-123');

        $this->assertDatabaseHas('memberships', [
            'organization_id' => $user->memberships()->sole()->organization_id,
            'user_id' => $user->id,
            'role' => Role::Owner->value,
            'is_active' => true,
        ]);

        $this->expectException(LogicException::class);

        app(InitialOwnerService::class)->bootstrap('Second Org', 'Second Owner', 'second-owner@example.test', 'Secret-password-123');
    }

    public function test_notification_intent_stays_pending_without_configuration_or_live_delivery(): void
    {
        $organization = Organization::create(['name' => 'Solveit', 'timezone' => 'Asia/Jakarta']);
        $event = app(OutboxWriter::class)->record($organization, 'telegram.notification.requested', 'notification', 1, 1, ['message' => 'Reminder']);

        (new DispatchPendingOutboxEvents)->handle();

        $this->assertDatabaseHas('outbox_events', [
            'id' => $event->id,
            'status' => 'pending',
            'attempts' => 0,
        ]);
        $this->assertDatabaseCount('telegram_deliveries', 0);
    }

    public function test_audit_and_payload_redaction_are_append_only(): void
    {
        $organization = Organization::create(['name' => 'Solveit', 'timezone' => 'Asia/Jakarta']);
        $event = app(AuditWriter::class)->write($organization, 'foundation.write', 'organization', $organization->id, 'success', after: ['authorization' => 'Bearer secret', 'url' => 'https://x.test?token=secret']);

        $this->assertSame('[REDACTED]', $event->after['authorization']);
        $this->assertStringContainsString('[REDACTED]', $event->after['url']);
        $this->expectException(LogicException::class);
        $event->update(['outcome' => 'changed']);
    }

    public function test_ssrf_guard_and_fake_adapters_preserve_safe_states(): void
    {
        $validator = new OutboundTargetValidator(new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return $host === 'public.example' ? ['8.8.8.8'] : ['127.0.0.1'];
            }
        });

        $this->assertSame('https://public.example/status', $validator->validate('https://public.example/status'));

        try {
            $validator->validate('http://private.example');
            $this->fail('Private target must be rejected.');
        } catch (\DomainException) {
        }

        $this->assertSame('unsupported', (new FakeCpanelAdapter)->discover()->status);
        $this->assertSame('HOST_KEY_MISMATCH', (new FakeSftpAdapter)->validate('host_key_mismatch')->reasonCode);
        $this->assertSame('retrying', (new FakeTelegramTransport)->send('rate_limited')->status);
        $this->expectException(LogicException::class);
        app(LiveConnectorGate::class)->assertEnabled();
    }

    public function test_security_contracts_and_remaining_fakes_are_deterministic(): void
    {
        $payload = '{"event":"test"}';
        $signature = hash_hmac('sha256', $payload, 'webhook-secret');

        $this->assertTrue((new WebhookSignatureVerifier)->isValid($payload, $signature, 'webhook-secret'));
        $this->assertFalse((new WebhookSignatureVerifier)->isValid($payload, 'invalid', 'webhook-secret'));
        $this->assertSame("'=formula", (new CsvValueEscaper)->escape('=formula'));

        $start = now()->startOfMinute();
        $clock = new FakeClock($start);
        $clock->advanceMinutes(5);
        $this->assertTrue($clock->now()->equalTo($start->addMinutes(5)));

        $storage = new FakeObjectStorage;
        $metadata = $storage->put('evidence/test.txt', 'test artifact');
        $this->assertSame('test artifact', $storage->get('evidence/test.txt'));
        $this->assertSame(hash('sha256', 'test artifact'), $metadata['sha256']);

        $queue = new FakeQueue;
        $queue->dispatch('safe-job');
        $queue->dispatch('failed-job', shouldFail: true);
        $this->assertSame('failed', $queue->jobs()[1]['status']);
    }
}
