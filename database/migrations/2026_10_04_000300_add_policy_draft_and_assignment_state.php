<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->json('draft_configuration')->nullable()->after('status');
        });
        Schema::table('policy_assignments', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('overrides');
            $table->index(['organization_id', 'resource_type', 'resource_id', 'is_active'], 'policy_assignment_active_scope');
        });
    }

    public function down(): void
    {
        Schema::table('policy_assignments', function (Blueprint $table): void {
            // InnoDB may replace the original FK index with the active-scope index on upgrade.
            $table->index('organization_id', 'pa_org_fk_idx');
            $table->dropIndex('policy_assignment_active_scope');
            $table->dropColumn('is_active');
        });
        Schema::table('policies', function (Blueprint $table): void {
            $table->dropColumn('draft_configuration');
        });
    }
};
