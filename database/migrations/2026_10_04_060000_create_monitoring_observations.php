<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('asset_id')->constrained();
            $table->foreignId('policy_version_id')->constrained();
            $table->string('environment_kind');
            $table->string('kind');
            $table->string('configuration_digest', 64);
            $table->json('configuration');
            $table->unsignedInteger('interval_seconds');
            $table->boolean('enabled')->default(true);
            $table->string('state')->default('unknown');
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedInteger('successes')->default(0);
            $table->timestamp('first_failed_at')->nullable();
            $table->timestamp('first_recovery_at')->nullable();
            $table->timestamp('next_due_at')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'asset_id', 'environment_kind', 'kind', 'configuration_digest'], 'monitor_canonical_unique');
        });
        Schema::create('monitor_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitor_id')->constrained();
            $table->foreignId('project_id')->constrained();
            $table->foreignId('environment_id')->constrained();
            $table->unique(['monitor_id', 'project_id', 'environment_id'], 'monitor_usage_unique');
        });
        Schema::create('observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('monitor_id')->constrained();
            $table->timestamp('scheduled_at');
            $table->timestamp('started_at');
            $table->timestamp('completed_at');
            $table->timestamp('observed_at');
            $table->timestamp('received_at');
            $table->timestamp('fresh_until');
            $table->string('outcome');
            $table->string('reason_code');
            $table->string('source_type');
            $table->string('source_id');
            $table->string('quality')->default('verified');
            $table->string('unit')->nullable();
            $table->json('value');
            $table->json('evidence');
            $table->boolean('fake')->default(false);
            $table->boolean('maintenance')->default(false);
            $table->boolean('retention_hold')->default(false);
            $table->unique(['monitor_id', 'scheduled_at']);
            $table->index(['organization_id', 'monitor_id', 'completed_at'], 'observation_scope_time');
        });
        Schema::create('daily_monitor_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitor_id')->constrained();
            $table->date('day');
            $table->unsignedInteger('passed');
            $table->unsignedInteger('failed');
            $table->unsignedInteger('unknown');
            $table->unsignedInteger('maintenance');
            $table->unsignedInteger('samples');
            $table->unique(['monitor_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_monitor_aggregates');
        Schema::dropIfExists('observations');
        Schema::dropIfExists('monitor_usages');
        Schema::dropIfExists('monitors');
    }
};
