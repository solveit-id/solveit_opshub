<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_test_runs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('connector_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('idempotency_key', 100);
            $t->string('request_digest', 64);
            $t->unsignedInteger('configuration_version');
            $t->string('candidate_reference')->nullable();
            $t->string('revoke_reference')->nullable();
            $t->enum('state', ['queued', 'running', 'completed', 'failed', 'cancelled'])->default('queued');
            $t->unsignedBigInteger('active_connector_id')->nullable()->storedAs("CASE WHEN state IN ('queued','running') THEN connector_id ELSE NULL END");
            $t->uuid('lease_owner')->nullable();
            $t->timestamp('leased_until')->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->string('reason_code')->nullable();
            $t->boolean('fake')->default(false);
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique('active_connector_id');
            $t->unique(['connector_id', 'actor_user_id', 'idempotency_key'], 'connector_test_idempotency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_test_runs');
    }
};
