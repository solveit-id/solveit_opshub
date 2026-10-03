<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->timestamp('last_evaluated_slot')->nullable();
        });
        Schema::create('maintenance_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('monitor_id')->constrained();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason');
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamps();
        });
        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('monitor_id')->constrained();
            $table->unsignedBigInteger('active_monitor_id')->nullable()->unique();
            $table->foreignId('previous_incident_id')->nullable()->constrained('incidents');
            $table->string('state')->default('open');
            $table->string('severity');
            $table->string('reason_code');
            $table->foreignId('assignee_user_id')->nullable()->constrained('users');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('first_failed_at');
            $table->timestamp('confirmed_down_at');
            $table->timestamp('last_failed_at');
            $table->timestamp('first_recovery_sample_at')->nullable();
            $table->timestamp('confirmed_recovered_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('closure_summary')->nullable();
            $table->boolean('alert_pending')->default(true);
            $table->boolean('flapping')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'state', 'severity']);
        });
        Schema::create('incident_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained();
            $table->foreignId('observation_id')->constrained();
            $table->unique(['incident_id', 'observation_id']);
        });
        Schema::create('incident_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained();
            $table->string('event');
            $table->timestamp('occurred_at');
            $table->boolean('suppressed')->default(false);
            $table->string('reason_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_transitions');
        Schema::dropIfExists('incident_observations');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('maintenance_windows');
        Schema::table('monitors', fn (Blueprint $table) => $table->dropColumn('last_evaluated_slot'));
    }
};
