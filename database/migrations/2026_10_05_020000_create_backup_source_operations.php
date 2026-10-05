<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->string('source_status', 32)->default('not_started');
            $table->string('transfer_status', 32)->default('not_started');
            $table->string('integrity_status', 32)->default('none');
            $table->dateTime('source_next_at', 6)->nullable();
        });
        Schema::create('backup_execution_controls', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedTinyInteger('maximum_parallel')->default(2);
        });
        DB::table('backup_execution_controls')->insert(['id' => 1, 'maximum_parallel' => 2]);
        Schema::create('backup_source_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backup_run_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('request_count')->default(0);
            $table->string('state', 32)->default('requesting');
            $table->string('provider_reference', 32)->nullable();
            $table->json('artifact_locator')->nullable();
            $table->char('stable_fingerprint', 64)->nullable();
            $table->dateTime('stable_since', 6)->nullable();
            $table->dateTime('started_at', 6);
            $table->dateTime('deadline_at', 6);
            $table->timestamps(6);
        });
        Schema::create('backup_source_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backup_source_operation_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('state', 32)->default('requesting');
            $table->string('provider_reference', 32)->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->dateTime('started_at', 6);
            $table->dateTime('completed_at', 6)->nullable();
            $table->unique(['backup_source_operation_id', 'number'], 'source_request_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_source_requests');
        Schema::dropIfExists('backup_source_operations');
        Schema::dropIfExists('backup_execution_controls');
        Schema::table('backup_runs', fn (Blueprint $table) => $table->dropColumn(['source_status', 'transfer_status', 'integrity_status', 'source_next_at']));
    }
};
