<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('hosting_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('backup_run_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('artifact_reference')->unique();
            $table->json('environment_ids');
            $table->string('source_kind', 16);
            $table->string('state', 32)->default('uploading');
            $table->string('object_reference', 100);
            $table->uuid('object_version');
            $table->string('key_reference', 200);
            $table->string('encryption', 64)->default('secretstream_xchacha20poly1305_v1');
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unsignedBigInteger('encrypted_bytes')->default(0);
            $table->char('sha256', 64)->nullable();
            $table->char('encrypted_sha256', 64)->nullable();
            $table->json('manifest')->nullable();
            $table->json('coverage_scopes')->nullable();
            $table->string('verification_level', 32)->default('none');
            $table->string('reason_code', 64)->nullable();
            $table->boolean('fake')->default(false);
            $table->boolean('legal_hold')->default(false);
            $table->boolean('restore_pending')->default(false);
            $table->dateTime('source_observed_at', 6);
            $table->dateTime('verified_at', 6)->nullable();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->timestamps(6);
        });
        Schema::create('backup_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backup_artifact_id')->constrained()->restrictOnDelete();
            $table->string('level', 32);
            $table->string('state', 32);
            $table->string('reason_code', 64)->nullable();
            $table->json('evidence');
            $table->dateTime('checked_at', 6);
        });
        Schema::create('backup_scope_goods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('hosting_account_id')->constrained()->restrictOnDelete();
            $table->string('scope', 32);
            $table->foreignId('backup_artifact_id')->constrained()->restrictOnDelete();
            $table->dateTime('source_observed_at', 6);
            $table->dateTime('verified_at', 6);
            $table->unique(['hosting_account_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_scope_goods');
        Schema::dropIfExists('backup_verifications');
        Schema::dropIfExists('backup_artifacts');
    }
};
