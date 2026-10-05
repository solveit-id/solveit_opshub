<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_artifacts', function (Blueprint $table): void {
            $table->timestamp('delete_leased_until', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backup_artifacts', fn (Blueprint $table) => $table->dropColumn('delete_leased_until'));
    }
};
