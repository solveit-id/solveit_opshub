<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renewal_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('renewal_cycle_id')->constrained()->cascadeOnDelete();
            $table->integer('threshold');
            $table->string('kind')->default('threshold');
            $table->string('severity');
            $table->string('state');
            $table->json('impacted_project_ids');
            $table->foreignId('outbox_event_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['renewal_cycle_id', 'kind', 'threshold'], 'renewal_reminder_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_reminders');
    }
};
