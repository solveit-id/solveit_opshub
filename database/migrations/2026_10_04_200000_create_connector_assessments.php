<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connectors', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('hosting_account_id')->constrained()->restrictOnDelete();
            $t->enum('kind', ['cpanel', 'sftp']);
            $t->enum('state', ['not_configured', 'connected', 'degraded', 'auth_failed', 'disabled'])->default('not_configured');
            $t->enum('validation_state', ['not_configured', 'live_unverified', 'validated_sandbox', 'unsupported_on_target'])->default('not_configured');
            $t->json('configuration');
            $t->unsignedInteger('version')->default(1);
            $t->boolean('writes_paused')->default(true);
            $t->timestamp('last_tested_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'hosting_account_id', 'kind']);
        });
        Schema::create('connector_assessments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('connector_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('configuration_version');
            $t->enum('operation', ['validate', 'discover', 'read', 'backup', 'reconcile']);
            $t->string('capability', 64);
            $t->enum('status', ['supported', 'unsupported', 'permission_denied', 'not_configured', 'unknown', 'fail']);
            $t->string('reason_code', 64)->nullable();
            $t->boolean('retryable');
            $t->enum('source', ['fake', 'provider']);
            $t->timestamp('observed_at');
            $t->json('evidence');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['connector_id', 'configuration_version', 'capability', 'id'], 'connector_capability_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_assessments');
        Schema::dropIfExists('connectors');
    }
};
