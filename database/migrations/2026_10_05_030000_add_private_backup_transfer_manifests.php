<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->json('transfer_manifest')->nullable();
            $table->unsignedBigInteger('transferred_bytes')->default(0);
            $table->unsignedInteger('transferred_files')->default(0);
            $table->unsignedInteger('transfer_errors')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', fn (Blueprint $table) => $table->dropColumn(['transfer_manifest', 'transferred_bytes', 'transferred_files', 'transfer_errors']));
    }
};
