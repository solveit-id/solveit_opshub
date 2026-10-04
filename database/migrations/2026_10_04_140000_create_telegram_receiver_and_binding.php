<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_binding_intents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('telegram_bot_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->unsignedInteger('identity_version');
            $t->timestamp('expires_at');
            $t->string('state', 20)->default('pending');
            $t->string('candidate_user_id', 32)->nullable();
            $t->string('candidate_chat_id', 32)->nullable();
            $t->timestamp('claimed_at')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('telegram_bindings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('telegram_bot_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('telegram_user_id', 32);
            $t->string('chat_id', 32);
            $t->unsignedInteger('identity_version');
            $t->boolean('enabled')->default(true);
            $t->timestamp('confirmed_at');
            $t->timestamps();
            $t->unique(['telegram_bot_id', 'user_id']);
            $t->unique(['telegram_bot_id', 'telegram_user_id']);
        });
        Schema::create('telegram_update_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('telegram_bot_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('update_id');
            $t->unsignedInteger('identity_version');
            $t->text('encrypted_payload');
            $t->string('status', 20)->default('pending');
            $t->string('result_code', 60)->nullable();
            $t->timestamp('received_at');
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->unique(['telegram_bot_id', 'update_id']);
        });
        Schema::create('telegram_callback_references', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('telegram_bot_id')->constrained()->restrictOnDelete();
            $t->foreignId('telegram_destination_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('binding_id')->nullable()->constrained('telegram_bindings')->restrictOnDelete();
            $t->string('reference_hash', 64)->unique();
            $t->string('action', 20);
            $t->string('aggregate_type', 20);
            $t->unsignedBigInteger('aggregate_id');
            $t->unsignedInteger('aggregate_version');
            $t->json('project_ids');
            $t->unsignedInteger('identity_version');
            $t->timestamp('expires_at');
            $t->foreignId('used_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
        });
        Schema::table('telegram_deliveries', function (Blueprint $t) {
            $t->foreign('binding_id')->references('id')->on('telegram_bindings')->restrictOnDelete();
            $t->foreignId('receipt_id')->nullable()->constrained('telegram_update_receipts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_deliveries', function (Blueprint $t) {
            $t->dropConstrainedForeignId('receipt_id');
            $t->dropForeign(['binding_id']);
        });
        Schema::dropIfExists('telegram_callback_references');
        Schema::dropIfExists('telegram_update_receipts');
        Schema::dropIfExists('telegram_bindings');
        Schema::dropIfExists('telegram_binding_intents');
    }
};
