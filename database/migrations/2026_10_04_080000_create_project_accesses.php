<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('project_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->unique(['project_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_accesses');
    }
};
