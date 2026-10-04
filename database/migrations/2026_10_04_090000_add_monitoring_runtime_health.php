<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_usages', fn (Blueprint $table) => $table->boolean('active')->default(true));
        Schema::create('incident_projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained();
            $table->foreignId('project_id')->constrained();
            $table->foreignId('environment_id')->nullable()->constrained();
            $table->unique(['incident_id', 'project_id']);
        });
        DB::table('incident_projects')->insertUsing(['incident_id', 'project_id', 'environment_id'], DB::table('incidents')->join('monitor_usages', 'monitor_usages.monitor_id', '=', 'incidents.monitor_id')->selectRaw('incidents.id, monitor_usages.project_id, MIN(monitor_usages.environment_id)')->groupBy('incidents.id', 'monitor_usages.project_id'));
        Schema::create('runtime_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained();
            $table->string('component');
            $table->timestamp('observed_at');
            $table->json('metadata')->nullable();
            $table->unique(['organization_id', 'component']);
        });
        Schema::table('job_runs', function (Blueprint $table): void {
            $table->timestamp('queue_enqueued_at')->nullable();
            $table->unsignedInteger('missed_slots')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_projects');
        Schema::table('monitor_usages', fn (Blueprint $table) => $table->dropColumn('active'));
        Schema::dropIfExists('runtime_heartbeats');
        Schema::table('job_runs', fn (Blueprint $table) => $table->dropColumn(['queue_enqueued_at', 'missed_slots']));
    }
};
