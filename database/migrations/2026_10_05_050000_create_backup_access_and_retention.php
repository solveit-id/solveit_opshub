<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_artifacts', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
            $table->uuid('delete_lease')->nullable();
            $table->string('source_cleanup_state', 32)->default('not_requested');
            $table->uuid('source_cleanup_lease')->nullable();
            $table->unique(['object_reference', 'object_version']);
        });
        Schema::create('backup_download_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backup_artifact_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->char('token_digest', 64)->unique();
            $table->dateTime('expires_at', 6);
            $table->dateTime('created_at', 6);
        });
        Schema::create('backup_retention_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('backup_policy_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('policy_version');
            $table->string('schedule_key', 64)->nullable()->unique();
            $table->string('state', 32)->default('dry_run');
            $table->json('decisions');
            $table->json('results')->nullable();
            $table->dateTime('expires_at', 6);
            $table->timestamps(6);
        });
        Schema::create('backup_schedule_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backup_policy_id')->constrained()->restrictOnDelete();
            $table->date('local_day');
            $table->dateTime('scheduled_at', 6);
            $table->foreignId('backup_run_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('state', 32);
            $table->string('reason_code', 64)->nullable();
            $table->unique(['backup_policy_id', 'local_day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_schedule_slots');
        Schema::dropIfExists('backup_retention_reports');
        Schema::dropIfExists('backup_download_links');
        Schema::table('backup_artifacts', function (Blueprint $table): void {
            $table->dropUnique(['object_reference', 'object_version']);
            $table->dropColumn(['version', 'delete_lease', 'source_cleanup_state', 'source_cleanup_lease']);
        });
    }
};
