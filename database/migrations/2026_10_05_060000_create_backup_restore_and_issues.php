<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_restore_drills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('backup_artifact_id')->constrained()->restrictOnDelete();
            $table->foreignId('operator_user_id')->constrained('users')->restrictOnDelete();
            $table->string('target_reference', 128);
            $table->uuid('workspace_reference')->unique();
            $table->string('target_kind', 32)->default('isolated');
            $table->string('runbook', 100);
            $table->string('state', 32)->default('queued');
            $table->string('reason_code', 64)->nullable();
            $table->uuid('lease_owner')->nullable();
            $table->dateTime('leased_until', 6)->nullable();
            $table->json('checks')->nullable();
            $table->json('evidence')->nullable();
            $table->string('evidence_reference', 128)->nullable();
            $table->boolean('fake')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('completed_at', 6)->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('active_artifact_id')->nullable()->storedAs("CASE WHEN state IN ('queued','running','unknown','manual_required') THEN backup_artifact_id ELSE NULL END")->unique();
        });
        Schema::create('backup_internal_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('hosting_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('backup_run_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('deduplication_key', 100)->unique();
            $table->string('kind', 32);
            $table->string('scope', 32)->nullable();
            $table->string('reason_code', 64);
            $table->string('state', 32)->default('open');
            $table->json('impacted_project_ids');
            $table->dateTime('opened_at', 6);
            $table->dateTime('resolved_at', 6)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(6);
        });
        Schema::create('backup_recovery_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backup_internal_incident_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('state', 32)->default('open');
            $table->dateTime('due_at', 6);
            $table->string('resolution_evidence', 128)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_recovery_tasks');
        Schema::dropIfExists('backup_internal_incidents');
        Schema::dropIfExists('backup_restore_drills');
    }
};
