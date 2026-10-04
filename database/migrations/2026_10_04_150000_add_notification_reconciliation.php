<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_deliveries', function (Blueprint $t) {
            $t->unsignedInteger('identity_version')->default(1);
            $t->timestamp('queued_at')->nullable();
            $t->boolean('uncertain')->default(false);
            $t->json('down_incident_ids')->nullable();
            $t->json('recovery_incident_ids')->nullable();
        });
        Schema::table('telegram_delivery_attempts', function (Blueprint $t) {
            $t->string('chat_rate_key', 64)->nullable();
            $t->index(['telegram_bot_id', 'chat_rate_key', 'started_at'], 'telegram_chat_rate_window');
        });
        Schema::create('telegram_integration_findings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('telegram_bot_id')->constrained()->restrictOnDelete();
            $t->string('recipient_reference', 64);
            $t->string('code', 60);
            $t->string('state', 20)->default('open');
            $t->string('action');
            $t->timestamp('first_detected_at');
            $t->timestamp('last_detected_at');
            $t->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('acknowledged_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->foreignId('verified_delivery_id')->nullable()->constrained('telegram_deliveries')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['telegram_bot_id', 'recipient_reference', 'code'], 'telegram_finding_unique');
        });
        Schema::create('telegram_digest_slots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('telegram_bot_id')->constrained()->restrictOnDelete();
            $t->foreignId('telegram_destination_id')->constrained()->restrictOnDelete();
            $t->date('local_date');
            $t->string('timezone');
            $t->foreignId('outbox_event_id')->constrained()->restrictOnDelete();
            $t->timestamps();
            $t->unique(['telegram_destination_id', 'local_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_digest_slots');
        Schema::dropIfExists('telegram_integration_findings');
        Schema::table('telegram_delivery_attempts', function (Blueprint $t) {
            $t->dropIndex('telegram_chat_rate_window');
            $t->dropColumn('chat_rate_key');
        });
        Schema::table('telegram_deliveries', function (Blueprint $t) {
            $t->dropColumn(['identity_version', 'queued_at', 'uncertain', 'down_incident_ids', 'recovery_incident_ids']);
        });
    }
};
