<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_subscriptions', function (Blueprint $table): void {
            $table->string('service_name')->nullable();
            $table->string('service_kind')->default('unknown');
            $table->foreignId('resource_asset_id')->nullable()->constrained('assets')->restrictOnDelete();
            $table->timestamp('renew_by')->nullable();
            $table->string('payment_status')->default('unknown');
            $table->unique(['organization_id', 'asset_id'], 'subscription_asset_unique');
            $table->unique(['organization_id', 'resource_asset_id', 'service_kind'], 'subscription_resource_unique');
        });
        Schema::create('renewal_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('active_subscription_id')->nullable()->constrained('service_subscriptions')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('state')->default('active');
            $table->json('expiry_snapshot');
            $table->timestamp('opened_at');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('evidence_id')->nullable()->constrained('evidences')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique('active_subscription_id', 'renewal_one_active_cycle');
            $table->unique(['service_subscription_id', 'sequence'], 'renewal_sequence_unique');
        });
        Schema::create('client_followups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('renewal_cycle_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('state')->default('open');
            $table->string('severity')->default('info');
            $table->string('purpose')->default('renewal');
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('next_followup_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('response_summary')->nullable();
            $table->text('blocker')->nullable();
            $table->text('commitment')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->text('snooze_reason')->nullable();
            $table->foreignId('snoozed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'next_followup_at'], 'followup_due_index');
        });
        Schema::create('contact_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_followup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->timestamp('sent_at');
            $table->string('manual_channel');
            $table->unsignedBigInteger('template_draft_id');
            $table->unsignedInteger('draft_version');
            $table->text('sent_body');
            $table->foreignId('evidence_id')->nullable()->constrained('evidences')->restrictOnDelete();
            $table->timestamp('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_attempts');
        Schema::dropIfExists('client_followups');
        Schema::dropIfExists('renewal_cycles');
        Schema::table('service_subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['resource_asset_id']);
            $table->dropUnique('subscription_resource_unique');
            $table->dropUnique('subscription_asset_unique');
            $table->dropColumn(['service_name', 'service_kind', 'resource_asset_id', 'renew_by', 'payment_status']);
        });
    }
};
