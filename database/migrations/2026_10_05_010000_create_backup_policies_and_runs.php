<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_write_controls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('paused')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(6);
        });
        Schema::create('backup_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('hosting_account_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('connector_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('enabled')->default(false);
            $table->json('configuration');
            $table->dateTime('approved_at', 6);
            $table->timestamps(6);
        });
        Schema::create('backup_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('hosting_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('backup_policy_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('run_reference')->unique();
            $table->unsignedInteger('policy_version');
            $table->unsignedInteger('connector_version');
            $table->json('policy_snapshot');
            $table->json('impacted_project_ids');
            $table->string('idempotency_key', 100);
            $table->char('request_digest', 64);
            $table->string('state', 32)->default('queued');
            $table->unsignedBigInteger('active_account_id')->nullable()->storedAs("CASE WHEN state IN ('queued','preflight','ready','awaiting_source','transferring','verifying','reconcile_required') THEN hosting_account_id ELSE NULL END");
            $table->unique('active_account_id');
            $table->unique(['backup_policy_id', 'actor_user_id', 'idempotency_key'], 'backup_run_idempotency');
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('lease_owner')->nullable();
            $table->dateTime('leased_until', 6)->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->boolean('fake')->default(false);
            $table->json('preflight_evidence')->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->timestamps(6);
        });
        Schema::create('backup_run_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backup_run_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('mode', 32);
            $table->string('state', 32)->default('claimed');
            $table->string('reason_code', 64)->nullable();
            $table->dateTime('started_at', 6);
            $table->dateTime('completed_at', 6)->nullable();
            $table->unique(['backup_run_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_run_attempts');
        Schema::dropIfExists('backup_runs');
        Schema::dropIfExists('backup_policies');
        Schema::dropIfExists('backup_write_controls');
    }
};
