<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_bots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('external_bot_id', 32)->nullable();
            $t->string('token_secret_reference');
            $t->string('webhook_secret_reference');
            $t->boolean('enabled')->default(false);
            $t->unsignedInteger('identity_version')->default(1);
            $t->timestamp('identity_verified_at')->nullable();
            $t->boolean('identity_fake')->default(false);
            $t->json('settings');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('telegram_destinations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('telegram_bot_id')->constrained()->cascadeOnDelete();
            $t->string('chat_id', 32);
            $t->string('label');
            $t->string('chat_type', 20);
            $t->json('project_ids');
            $t->boolean('all_projects')->default(false);
            $t->boolean('owner_route')->default(false);
            $t->json('severities');
            $t->foreignId('member_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('scope_confirmed_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('scope_confirmed_at');
            $t->boolean('enabled')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['telegram_bot_id', 'chat_id']);
        });
        Schema::create('telegram_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('telegram_bot_id')->constrained()->restrictOnDelete();
            $t->foreignId('telegram_destination_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('binding_id')->nullable();
            $t->foreignId('outbox_event_id')->constrained()->restrictOnDelete();
            $t->string('recipient_reference', 64);
            $t->string('notification_kind', 40);
            $t->unsignedInteger('chunk_index')->default(0);
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('parent_delivery_id')->nullable()->constrained('telegram_deliveries')->restrictOnDelete();
            $t->foreignId('coalesced_into_id')->nullable()->constrained('telegram_deliveries')->restrictOnDelete();
            $t->json('event_ids')->nullable();
            $t->json('project_ids');
            $t->text('text');
            $t->json('reply_markup')->nullable();
            $t->string('severity', 20);
            $t->unsignedTinyInteger('priority');
            $t->string('state', 20)->default('pending');
            $t->timestamp('available_at', 6);
            $t->timestamp('first_attempt_at', 6)->nullable();
            $t->timestamp('last_attempt_at', 6)->nullable();
            $t->timestamp('sent_at', 6)->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->uuid('lease_owner')->nullable();
            $t->timestamp('lease_until', 6)->nullable();
            $t->unsignedInteger('lease_version')->default(0);
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->string('result_code', 60)->nullable();
            $t->string('message_id', 64)->nullable();
            $t->boolean('fake')->default(false);
            $t->timestamps();
            $t->unique(['outbox_event_id', 'recipient_reference', 'notification_kind', 'chunk_index', 'revision'], 'delivery_dedup_unique');
            $t->index(['state', 'priority', 'available_at'], 'telegram_dispatch_due');
        });
        Schema::create('telegram_delivery_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('telegram_bot_id')->constrained()->restrictOnDelete();
            $t->foreignId('telegram_delivery_id')->constrained()->cascadeOnDelete();
            $t->string('recipient_reference', 64);
            $t->unsignedTinyInteger('attempt');
            $t->timestamp('started_at', 6);
            $t->timestamp('finished_at', 6)->nullable();
            $t->string('outcome', 30)->nullable();
            $t->string('result_code', 60)->nullable();
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->string('message_id', 64)->nullable();
            $t->boolean('fake')->default(false);
            $t->unique(['telegram_delivery_id', 'attempt']);
            $t->index(['telegram_bot_id', 'started_at']);
            $t->index(['recipient_reference', 'started_at']);
        });
        Schema::create('incident_notification_histories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('incident_id')->constrained()->restrictOnDelete();
            $t->string('recipient_reference', 64);
            $t->foreignId('down_delivery_id')->nullable()->constrained('telegram_deliveries')->restrictOnDelete();
            $t->foreignId('recovery_delivery_id')->nullable()->constrained('telegram_deliveries')->restrictOnDelete();
            $t->boolean('fake')->default(false);
            $t->timestamps();
            $t->unique(['incident_id', 'recipient_reference'], 'incident_destination_history_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_notification_histories');
        Schema::dropIfExists('telegram_delivery_attempts');
        Schema::dropIfExists('telegram_deliveries');
        Schema::dropIfExists('telegram_destinations');
        Schema::dropIfExists('telegram_bots');
    }
};
